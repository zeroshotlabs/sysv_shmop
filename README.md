## System V Shared Memory Table & Deque on Ring Buffer

A PHP FFI module that implements a couple of data structures on of SysV shared memory.
- standard table with rows and columns
- addition of ring/circular buffer storage
- addition of deque semantics.

### Installation

You can try the install using composer:

```bash
composer require zeroshotlabs/sysv_shmop
```

That, however, may very well not work, and there have been some recent changes that
haven't been fully tested.

Easy enough too is copying things where needed and adding:

`incude src/header.inc`


PRs welcome.
