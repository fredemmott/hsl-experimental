<?hh // strict
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

use namespace HH\Lib\{IO, OS, Str};

use function Facebook\FBExpect\expect; // @oss-enable
use type Facebook\HackTest\HackTest; // @oss-enable
// @oss-disable: use type HackTest;

/** Test pipes specifically, and core IO functions.
 *
 * This is basic coverage for all `LegacyPHPResourceHandle`s
 */
// @oss-disable: <<Oncalls('hack')>>
final class PipeTest extends HackTest {
  public async function testWritesAreReadableAsync(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    await $w->writeAsync("Hello, world!\nHerp derp\n");

    $read = await $r->readAsync();
    expect($read)->toEqual("Hello, world!\nHerp derp\n");

    await $w->closeAsync();
    $s = await $r->readAsync();
    expect($s)->toEqual('');
  }

  public async function testReadWithoutLimitAsync(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    await $w->writeAsync("Hello, world!\nHerp derp\n");
    await $w->closeAsync();
    $s = await $r->readAsync();
    expect($s)->toEqual("Hello, world!\nHerp derp\n");
  }

  public async function testPartialReadAsync(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    await $w->writeAsync('1234567890');
    $s = await $r->readAsync(5);
    expect($s)->toEqual('12345');
    $s = await $r->readAsync(5);
    expect($s)->toEqual('67890');
  }

  public async function testReadTooManyAsync(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    await $w->writeAsync('1234567890');
    await $w->closeAsync();
    $s = await $r->readAsync(11);
    expect($s)->toEqual('1234567890');
  }

  public async function testInteractionAsync(): Awaitable<void> {
    // Emulate a client-server environment
    list($cr, $sw) = IO\pipe_nd();
    list($sr, $cw) = IO\pipe_nd();

    concurrent {
      await async { // client
        await $cw->writeAsync("Herp\n");
        $response = await $cr->readAsync();
        expect($response)->toEqual("Derp\n");
        await $cw->writeAsync("Foo\n");
        $response = await $cr->readAsync();
        expect($response)->toEqual("Bar\n");
      };
      await async { // server
        $request = await $sr->readAsync();
        expect($request)->toEqual("Herp\n");
        await $sw->writeAsync("Derp\n");
        $request = await $sr->readAsync();
        expect($request)->toEqual("Foo\n");
        await $sw->writeAsync("Bar\n");
      };
    }
  }

  public async function testReadFromClosedPipe(): Awaitable<void> {
    // Intent is to:
    // - make sure we throw the expected errno
    // - make sure there isn't an infinite loop
    list($r, $w) = IO\pipe_nd();
    await $r->closeAsync();
    await $w->closeAsync();
    $ex = expect(async () ==> await $r->readAsync())->toThrow(
      OS\ErrnoException::class,
    );
    expect($ex->getErrno())->toEqual(OS\Errno::EBADF);
  }

  public async function testReadFromPipeClosedOnOtherEnd(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    await $w->closeAsync();
    // Standard behavior for `read(fd)` with "no more data is coming" rather
    // than "no more available now"
    expect(await $r->readAsync())->toEqual('');
  }

  public async function testInterleavedReads(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        $w->write("Hello, ");
        await \HH\Asio\later();
        $w->write("world.");
      };
      await async {
        expect(await $r->readAsync())->toEqual("Hello, ");
        expect(await $r->readAsync())->toEqual("world.");
      };
    }
  }

  public async function testReadAll(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        $w->write("Hello, ");
        await \HH\Asio\later();
        $w->write("world.");
        await $w->closeAsync();
      };
      await async {
        expect(await $r->readAllAsync())->toEqual("Hello, world.");
      };
    }
  }

  public async function testReadAllTimeout(): Awaitable<void> {
    list($r, $_w) = IO\pipe_nd();
    expect(
      async () ==> await $r->readAllAsync(/* max_bytes = */ null, 1 /* ns */),
    )->toThrow(OS\TimeoutException::class);
  }

  public async function testReadAllTruncated(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        $w->write("Hello, ");
        await \HH\Asio\later();
        $w->write("world.");
      };
      await async {
        expect(await $r->readAllAsync(8))->toEqual("Hello, w");
      };
    }
  }

  public async function testReadFixedSize(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    $w->write("Hello");
    expect(await $r->readFixedSizeAsync(3))->toEqual('Hel');

    concurrent {
      await async {
        await \HH\Asio\later();
        $w->write(", world");
        await $w->closeAsync();
      };
      expect(await $r->readFixedSizeAsync(3))->toEqual('lo,', 'multi-packet');
    }

    expect(async () ==> await $r->readFixedSizeAsync(100))->toThrow(
      OS\BrokenPipeException::class,
      null,
      'requested more data than is available',
    );
  }

  public async function testPartialWrites(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        await \HH\Asio\later();
        expect($w->write(Str\repeat('a', 1024 * 1024)))->toBeLessThan(
          1024 * 1024,
        );
      };
      await async {
        expect(await $r->readAsync(3))->toEqual('aaa');
      };
    }
  }

  public async function testWriteAll(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        await \HH\Asio\later();
        await $w->writeAllAsync(Str\repeat('a', 1024 * 1024));
        await $w->writeAsync('foo');
        await $w->closeAsync();
      };
      await async {
        expect(Str\length(await $r->readAllAsync()))->toEqual((1024 * 1024) + 3);
      };
    }
  }

  public async function testWriteAllTruncated(): Awaitable<void> {
    list($r, $w) = IO\pipe_nd();
    concurrent {
      await async {
        await \HH\Asio\later();
        // expect($lambda)->toThrow() blocks forever if used here:
        //
        // The inner `HH\Asio\join()` creates a new async context with only the
        // dependencies of the provided Awaitable. The concurrent reader
        // awaitable is not a dependency of this writer awaitable (they're
        // more like coroutines - really, the dependency is circular), so
        // it's not executed in the `\HH\Asio\join()`, so it hangs.
        $caught = null;
        try {
          await $w->writeAllAsync(Str\repeat('a', 1024 * 1024));
        } catch (OS\BrokenPipeException $ex) {
          $caught = $ex;
        }
        expect($caught)->toBeInstanceOf(OS\BrokenPipeException::class);
      };
      await async {
        try {
          expect(await $r->readAsync(4))->toEqual('aaaa');
        } finally {
          await $r->closeAsync();
        }
      };
    }
  }
}
