<?php declare(strict_types=1);
namespace stackware\shmdeq;

use \Countable;
use \RuntimeException;
use \IteratorAggregate;
use \ArrayAccess;
use \Exception;
use \Generator;
use \Iterator;
use \FFI;



class shmop_deq extends shmop_table_base implements ArrayAccess
{
    private $head;
    private $tail;

    public int $shm_key = 0;

    public closure $write_row_cb;


    public function __construct( int $key,array $cols,int $max_rows )
    {
        parent::__construct($key, $cols, $max_rows);

        $this->shm_key = $key;

        $this->head = $this->ffi->cast("int *", $this->shm_addr);
        $this->tail = $this->ffi->cast("int *", $this->ffi->cast("char *", $this->shm_addr) + $this->int_size);

        if( $this->head[0] === 0 && $this->tail[0] === 0 )
            $this->head[0] = $this->tail[0] = 0;

        $this->write_row_cb = function($row) {
            $this->write_row($this->tail[0], $row);

            $this->tail[0] = ($this->tail[0] + 1) % $this->max_rows;

            if( $this->tail[0] === $this->head[0] )
                $this->head[0] = ($this->head[0] + 1) % $this->max_rows;
        };
    }

    public function push_row( array $row_data )
    {
        array_walk($row_data,($this->write_row_cb( $row )));
    }

    public function tail_row( int $lines = 1 ): array|null
    {
        if ($this->head[0] === $this->tail[0])
            return null; // Empty deque

        $rows = [];
        $current_row = ($this->tail[0] - 1 + $this->max_rows) % $this->max_rows;

        for ($i = 0; $i < $lines; $i++) {
            $row_data = array_combine(
                array_keys($this->columns),
                array_map(fn($column) => $this->read_cell($current_row, $column), array_keys($this->columns))
            );

            $rows[] = $row_data;
            $current_row = ($current_row - 1 + $this->max_rows) % $this->max_rows;

            if ($current_row === ($this->head[0] - 1 + $this->max_rows) % $this->max_rows) {
                break; // Reached the beginning of the deque
            }
        }

        return array_reverse($rows);
    }

    public function head_row( int $lines = 1 ): array|null
    {
        if ($this->head[0] === $this->tail[0])
            return null; // Empty deque

        $rows = [];
        $current_row = $this->head[0];

        for ($i = 0; $i < $lines; $i++) {
            $row_data = array_combine(
                array_keys($this->columns),
                array_map(fn($column) => $this->read_cell($current_row, $column), array_keys($this->columns))
            );

            $rows[] = $row_data;
            $current_row = ($current_row + 1) % $this->max_rows;

            if ($current_row === $this->tail[0]) {
                break; // Reached the end of the deque
            }
        }

        return $rows;
    }

    public function column( $column, bool $n2o = true, ?int $length = null ): array
    {
        $count = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
        $length = min($length ?? $count, $count);

        if ($length === 0)
            return [];

        $start_row = $n2o ? ($this->tail[0] - 1 + $this->max_rows) % $this->max_rows : $this->head[0];

        return $this->read_column($column, $start_row, $length, $n2o);
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool
    {
        if (is_array($offset) && count($offset) == 2) {
            [$column, $row] = $offset;
            $row_offset = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
            return isset($this->columns[$column]) && $row >= 0 && $row < $row_offset;
        }
    
        $row_offset = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
        return is_int($offset) && $offset >= 0 && $offset < $row_offset;
    }

    public function offsetGet($offset): mixed
    {
        if (!$this->offsetExists($offset)) {
            throw new \OutOfBoundsException("Invalid offset");
        }
    
        if (is_array($offset) && count($offset) === 2) {
            [$column, $row] = $offset;
            $actual_row = ($this->head[0] + $row) % $this->max_rows;
            return $this->read_cell($actual_row, $column);
        }
    
        $actual_row = ($this->head[0] + $offset) % $this->max_rows;
        $read_cell_callback = function ($column) use ($actual_row) {
            return $this->read_cell($actual_row, $column);
        };
    
        return array_combine(array_keys($this->columns), array_map($read_cell_callback, array_keys($this->columns)));
    }
    
    public function offsetSet($offset, $value): void
    {
        throw new \RuntimeException("shmop_deq is read-only through ArrayAccess");
    }

    public function offsetUnset($offset): void
    {
        throw new \RuntimeException("Cannot unset values in shmop_deq");
    }
}


    // public function sample(int $n): self
    // {
    //     $count = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
    //     if (abs($n) > $count)
    //         throw new \InvalidArgumentException("Sample size cannot exceed available data");

    //     $sampled = new self(0, $this->columns, $this->max_rows);
    //     $sampled->shm_addr = $this->shm_addr;
    //     $sampled->data_start = $this->data_start;

    //     if ($n > 0) {
    //         $sampled->head[0] = ($this->tail[0] - $n + $this->max_rows) % $this->max_rows;
    //         $sampled->tail[0] = $this->tail[0];
    //     } else {
    //         $sampled->head[0] = $this->head[0];
    //         $sampled->tail[0] = ($this->head[0] - $n) % $this->max_rows;
    //     }

    //     return $sampled;
    // }



    
// // adds chart/table structure to shmoop 

// class shmop_deq
// {
//     private const IPC_CREAT = 01000;

//     private $ffi;
//     private $shmAddr;
//     private $columns;
//     private $rowCount;
//     private $rowSize;
//     private $dataStart;
//     private $head;
//     private $tail;
//     private $int_sz;
//     private array $col_key_map;


//     public function __construct($key, array $columnStructure, int $length )
//     {
//         $this->ffi = FFI::cdef("
//             #define IPC_CREAT 0001000
            
//             typedef unsigned int key_t;
//             typedef int shmatt_t;
            
//             void *shmat(int shmid, const void *shmaddr, int shmflg);
//             int shmdt(const void *shmaddr);
//             int shmget(key_t key, size_t size, int shmflg);
            
//             void *memcpy(void *dest, const void *src, size_t n);
//         ");

//         $offset = 0;
//         $this->columns = [];
//         foreach ($columnStructure as $name => $width) {
//             $this->columns[$name] = ['offset' => $offset, 'width' => $width];
//             $offset += $width;
//         }

//         $this->int_sz = FFI::sizeof($this->ffi->type("int"));

//         $this->rowCount = $length;
//         $this->rowSize = $offset;
//         $totalSize = $this->rowSize * $length + 2 * $this->int_sz;

//         $shmId = $this->ffi->shmget($key, $totalSize, 0666 | self::IPC_CREAT);
//         if ($shmId == -1) {
//             throw new Exception("Failed to get shared memory segment");
//         }

//         $this->shmAddr = $this->ffi->shmat($shmId, NULL, 0);
//         if ($this->shmAddr == $this->ffi->cast("void *", -1)) {
//             throw new Exception("Failed to attach shared memory segment");
//         }

//         $this->head = $this->ffi->cast("int *", $this->shmAddr);
//         $this->tail = $this->ffi->cast("int *", $this->shmAddr + $this->int_sz);
//         $this->dataStart = $this->shmAddr + 2 * $this->int_sz;


//         if ($this->head[0] == 0 && $this->tail[0] == 0) {
//             $this->head[0] = 0;
//             $this->tail[0] = 0;
//         }

//         $this->col_key_map = array_combine(array_keys($this->columns),range(0,count($this->columns)-1));
//     }


    // public function push(array $rowData)
    // {
    //     if (count($rowData) !== count($this->columns)) {
    //         throw new Exception("Row data does not match column structure: ".print_r($rowData,true)."\n\n".print_r($this->columns,true));
    //     }

    //     $writePos = $this->dataStart + ($this->tail[0] * $this->rowSize);

    //     foreach ($this->columns as $column => $info) {

    //         $data = str_pad(substr((string)$rowData[$this->col_key_map[$column]], 0, $info['width']), $info['width'], "\0");
    //         $this->ffi->memcpy($writePos + $info['offset'], $data, $info['width']);
    //     }

    //     $this->tail[0] = ($this->tail[0] + 1) % $this->rowCount;
    //     if ($this->tail[0] == $this->head[0]) {
    //         $this->head[0] = ($this->head[0] + 1) % $this->rowCount;
    //     }
    // }

//     public function pop()
//     {
//         if ($this->head[0] == $this->tail[0]) {
//             return null; // Empty deque
//         }

//         $readPos = $this->dataStart + ($this->head[0] * $this->rowSize);

//         $rowData = [];
//         foreach ($this->columns as $column => $info) {
//             $rowData[$column] = rtrim(FFI::string($readPos + $info['offset'], $info['width']), "\0");
//         }

//         $this->head[0] = ($this->head[0] + 1) % $this->rowCount;

//         return $rowData;
//     }


//     // n2o new to oldest default
//     // length relative to begenning
//     public function read_col(string $column, bool $n2o = true, ?int $length = null): array
//     {
//         if (!isset($this->columns[$column])) {
//             throw new InvalidArgumentException("Invalid column: $column");
//         }

//         if ($length !== null && $length < 0) {
//             throw new InvalidArgumentException("Length must be non-negative or null");
//         }

//         $count = ($this->tail[0] - $this->head[0] + $this->rowCount) % $this->rowCount;
//         if ($count === 0 || $length === 0) {
//             return [];
//         }

//         $length = ($length === null || $length > $count) ? $count : $length;

//         $result = [];
//         $columnInfo = $this->columns[$column];
        
//         for ($i = 0; $i < $length; $i++) {
//             $index = $n2o
//                 ? ($this->tail[0] - 1 - $i + $this->rowCount) % $this->rowCount
//                 : ($this->head[0] + $i) % $this->rowCount;
            
//             $readPos = $this->dataStart + ($index * $this->rowSize) + $columnInfo['offset'];
//             $data = FFI::string($readPos, $columnInfo['width']);
//             $result[] = rtrim($data, "\0");
//         }

//         return $result;
//     }


//     public function read_row(int $index)
//     {
//         $count = ($this->tail[0] - $this->head[0] + $this->rowCount) % $this->rowCount;
        
//         if ($index < 0 || $index >= $count) {
//             return null;  // Index out of bounds
//         }

//         $actualIndex = ($this->head[0] + $index) % $this->rowCount;
//         $readPos = $this->dataStart + ($actualIndex * $this->rowSize);

//         $rowData = [];
//         foreach ($this->columns as $column => $info) {
//             $data = $this->ffi->string($readPos + $info['offset'], $info['width']);
//             $rowData[$column] = rtrim($data, "\0");
//         }

//         return $rowData;
//     }    

//     public function __destruct()
//     {
//         $this->ffi->shmdt($this->shmAddr);
//     }
// }




//     //        oldest ________________> newest
//     //       foreach ----- > 
//     //               0123456789X12345
//     // un/shift off  >>>>>>>>>>>>>>>> pop/push on end
//     // chunk (4)        |   |   |   |


//     // ArrayAccess implementation; array access returns static array chunks (or the full array)

//     // positive int offset is a chunk size which must be evenly divideable into length
//     // negative int is that many elements from the beginning (most recent)
//     // empty/0/null returns the whole array
//     // values are qdecimal or string depending on column
//     public function offsetGet( mixed $offset = 0 ): array|string|qdecimal
//     {
//         $offset = (int)$offset;

//         if( $offset < 0 )
//             return ($offset===-1?(array_slice($this->array,$offset,null,false)[0]):array_slice($this->array,$offset,null,false));
//         else
//             return $this->chunk(empty($offset)?0:(int)$offset);
//         // yield from array_slice($this->array,$offset[0]??$offset,$offset[1]??null);
//     }

//     // only empty offset supported, append $value
//     public function offsetSet( mixed $offset,mixed $value ): void
//     {
//         if( empty($offset) )
//             $this->_push($value);
//         else
//             throw new exception('null only for append');
//     }
//     public function offsetUnset( mixed $offset ): void
//     {
//         throw new exception('offsetUnset not supported');
//     }
//     public function offsetExists( mixed $offset ): bool
//     {
//         throw new exception('offsetExists not supported');
//     }

//     public function toArray(): array
//     {
//         return $this->chunk(null);
//     }
    
//     // "static" or possibly "corrupted" iteration
//     public function count(): int
//     {
//         return count($this->array);
//     }

//     public function rewind(): void {
//         $this->position = 0;
//     }

//     #[\ReturnTypeWillChange]
//     public function current(): mixed {
//         return $this->array[$this->position];
//     }

//     public function key(): mixed {
//         return $this->position;
//     }

//     public function next(): void {
//         ++$this->position;
//     }

//     public function valid(): bool {
//         return isset($this->array[$this->position]);
//     }

//     // returns static array of BigNumbers or strings based on how object was created
//     // if length is 0 a single chunk is returned (the whole array as the first element of an array)
//     // if length is null the whole array is returned flat
//     // todo: maybe some locking for chunking consistecy could be handy
//     public function chunk( int|null $length = 0 ): array
//     {
//         if( $length === 0 )
//             return [($this->array)];
//         else if( $length === null )
//             return ($this->array);
//         else if( ($length = abs($length)) > 0 && $length <= $this->tape_length && !($this->tape_length % $length) )
//             return array_chunk($this->array,$length);
//         else
//             throw new Exception("\nchunk() invalid uneven length $length from $this->tape_length");
//     }


//     /**
//      * HLOC of full tape of chunked length - always qdecimals
//      * Either entire tape or specified length of chunks where each chunk is maxed/etc.
//      */
//     function high( int|null $length = null ): array|qdecimal|string
//     {
//         if( $length === null )
//         {
//             return qdecimal::max(...$this->chunk(null));
//         }
//         else
//             return array_map(fn( $s ) => max($s),$this->chunk($length,true));
//     }

//     function low( int|null $length = null ): array|qdecimal|string
//     {
//         if( $length === null )
//             return qdecimal::min(...$this->chunk(null));
//         else
//             return array_map(fn( $s ) => min($s),$this->chunk($length,true));
//     }

//     function open( int|null $length = null ): array|qdecimal|string
//     {
//         if( $length === null )
//             return $this->array[0];
//         else
//             return array_map(fn( $s ) => $this->array[0],$this->chunk($length));
//     }

//     function close( int|null $length = null ): bool  //  |array|qdecimal|string
//     {
//         if( $length === null )
//             return $this->array[array_key_last($this->array)];
//         else
//             return array_map(fn( $s ) => $this->array[array_key_last($s)],$this->chunk($length));
//     }

//     // if length is negative, that many recent values are used and the return is a single qdecimal
//     // otherwise it's a chunk size
//     function sum( int|null $length = null ): array|qdecimal|string
//     {
//         if( $length === null )
//         {
//             return count($this->array)===0?qdecimal::of(0):qdecimal::sum(...$this->array);
//         }
//         else
//         {
//             if( $length < 0 )
//                 return qdecimal::sum(...array_slice($this->array,$length,null,false));
//             else
//                 return array_map(fn( $s ) => qdecimal::sum(...$s),$this->chunk($length));
//         }
//     }

//     // doesn't filter out nulls/0s
//     // if length is negative, that many recent values are used and the return is a single qdecimal
//     // otherwise it's a chunk size
//     function avg( int|null $length = null ): array|qdecimal|string
//     {
//         if( count($this->array) === 0 )
//             return qdecimal::of(0);

//         if( $length === null )
//         {
//             return $this->sum()->dividedBy($this->tape_length,$this->scale,roundm::HALF_EVEN);
//         }
//         else
//         {
//             if( $length < 0 )
//                 return qdecimal::sum(...array_slice($this->array, $length, null, false))->dividedBy(abs($length),$this->scale,roundm::HALF_EVEN);
//             else
//                 return array_map(function( $v ) use ($length) {
//                                         return qdecimal::sum(...$v)->dividedBy($length,$this->scale,roundm::HALF_EVEN);
//                                  },$this->chunk($length));
//         }
//     }

//     // performs a trader_* function on the current tape channel (deque)
//     // for cross tape indicators see tape_deck::__call
//     // https://github.com/TA-Lib/ta-lib-python/blob/master/docs/funcs.md
//     function __call( string $name,array $args )
//     {
//         $name = "trader_{$name}";

//         // var_dump($name);
//         // var_dump($args);

//         if( !function_exists($name) )
//             throw new Exception("Invalid method $name");

//         // taking [0] here is probably wrong!
//         // array_walk($args,function( &$a,$k,$v ) {
//         //     $a = is_array($a)?$this->chunk($a[$k])[0]:$a;
//         // },'');
// //        return $name(...$args);
//         while( true )
//             yield $name($this->chunk($args[0]),...$args);
//     }
// }



//     // performs a trader_* function across tapes
//     // takes the actual arrays of data to use
//     // use tape::__call for getting that data
//     function __call( string $name,array $args )
//     {
// //        throw new Exception("$name not implemented in ".get_class($this));


//         $name = "trader_$name";

//         if( !function_exists($name) )
//             throw new Exception("Invalid TA calc method $name");

//         // array_walk($args,function( &$a,$k,$v ) {
//         //     $a = is_array($a)?$this->chunk($a[$k])[0]:$a;
//         // },'');

//         return $name(...$args);
//     }



