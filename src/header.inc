<?php declare(strict_types=1);
namespace stackware\ppmq;


const O_RDONLY    = 0x00;
const O_WRONLY    = 0x01;
const O_RDWR      = 0x02;
const O_CREAT     = 0x40;
const O_EXCL      = 0x80;
const O_NONBLOCK  = 0x800;


use \FFI;
use \FFI\CData as cdata;
// use \Exception;


function zerod( cdata $ffi_struct ): void
{
    FFI::memset($ffi_struct, 0, FFI::sizeof($ffi_struct));
}

// function flatten( $arr ): string
// {
//     echo "ASDFSADFD";
//     echo "\n".implode("\n\n", array_map(fn($arr) => implode(" ", $arr), $v));
// }


// const SIGRTMIN = 34;
// const SIG_BLOCK = 0;
// const SIGEV_SIGNAL = 0;

// const O_RDONLY    = 0x00;
// const O_WRONLY    = 0x01;
// const O_RDWR      = 0x02;
// const O_CREAT     = 0x40;
// const O_EXCL      = 0x80;
// const O_NONBLOCK  = 0x800;


// const S_IRUSR = 256;    // Octal 0400
// const S_IWUSR = 128;    // Octal 0200

// const RLIMIT_NOFILE = 7;
// const RLIMIT_MSGQUEUE = 12;

const ONE_MB = 1048576;
const TWO_MB = 2097152;




require('php_mqueue.php');
require('limits.php');

