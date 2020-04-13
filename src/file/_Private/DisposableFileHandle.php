<?hh
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace HH\Lib\_Private\_File;

use namespace HH\Lib\{File, IO};
use namespace HH\Lib\_Private\_IO;

<<__ConsistentConstruct>>
abstract class DisposableFileHandle<T as File\CloseableHandle>
  extends _IO\DisposableHandleWrapper<T>
  implements File\Handle {
  final public function __construct(T $impl) {
    parent::__construct($impl);
  }

  final public function getPath(): File\Path {
    return $this->impl->getPath();
  }

  final public function getSize(): int {
    return $this->impl->getSize();
  }

  <<__ReturnDisposable>>
  public function lock(File\LockType $mode): File\Lock {
    return $this->impl->lock($mode);
  }

  <<__ReturnDisposable>>
  public function tryLockx(File\LockType $mode): File\Lock {
    return $this->impl->tryLockx($mode);
  }

  public async function seekAsync(int $offset): Awaitable<void> {
    return await $this->impl->seekAsync($offset);
  }

  public function tell(): int {
    return $this->impl->tell();
  }
}
