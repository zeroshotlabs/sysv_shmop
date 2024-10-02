<?php declare(strict_types=1);
namespace stackware\libffi;


const O_RDONLY    = 0x00;
const O_WRONLY    = 0x01;
const O_RDWR      = 0x02;
const O_CREAT     = 0x40;
const O_EXCL      = 0x80;
const O_NONBLOCK  = 0x800;


use \FFI;
use \FFI\CData as cdata;



function zerod( cdata $ffi_struct ): void
{
    FFI::memset($ffi_struct, 0, FFI::sizeof($ffi_struct));
}



const ONE_MB = 1048576;
const TWO_MB = 2097152;



// typedef unsigned int key_t;
// typedef int shmatt_t;
// extern int errno;
// void *shmat(int shmid, const void *shmaddr, int shmflg);
// int shmdt(const void *shmaddr);
// int shmget(key_t key, size_t size, int shmflg);
// void *memcpy(void *dest, const void *src, size_t n);



require('src/shmop_ringbuf.php');
require('src/shmop_deque.php');


