<?php declare(strict_types=1);
namespace qubit;

// use \Ds\Sequence;
// use \Ds\Map;
// use \Ds\Collection;
// use \Ds\JsonSerializable;
use \Countable;
// use \Shmop as shmop;
// use \SysvMessageQueue as sysvq;

use \RuntimeException;

// IteratorAggregate

// use \Ds\Traversable;
use \IteratorAggregate;
use \ArrayAccess;
// use Ds\Traits\SquaredCapacity;
use \Exception;
use \Generator;
use \Iterator;
use \FFI;


abstract class shmop_table_base
{
    protected array $_id = [];

    protected $ffi;
    protected $shm_addr;
    protected $max_rows;
    protected $columns;
    protected $column_map;
    protected int $row_count;
    protected $row_size;
    protected $data_start;
    protected $int_size;
    protected const IPC_CREAT = 01000;

    
    public function __construct( $key, array $column_structure, int $max_rows )
    {
        $this->ffi = FFI::cdef("
            typedef unsigned int key_t;
            typedef int shmatt_t;
            extern int errno;
            void *shmat(int shmid, const void *shmaddr, int shmflg);
            int shmdt(const void *shmaddr);
            int shmget(key_t key, size_t size, int shmflg);
            void *memcpy(void *dest, const void *src, size_t n);
        ");

        $this->int_size = FFI::sizeof($this->ffi->type("int"));
        $this->max_rows = $max_rows;
        $this->columns = array_values($column_structure);
        $this->column_map = array_flip(array_keys($column_structure));
        $this->row_size = array_sum($this->columns);

        $total_size = ($this->row_size * $max_rows) + (2 * $this->int_size);

        $shm_id = $this->ffi->shmget($key, $total_size, 0666 | self::IPC_CREAT);

//        var_dump(\debug_print_backtrace());

        if ($shm_id == -1)
            throw new RuntimeException("Failed to get shared memory segment: ".$this->ffi->errno);

        $this->shm_addr = $this->ffi->shmat($shm_id, NULL, 0);

        if ($this->shm_addr == $this->ffi->cast("void *", -1))
            throw new RuntimeException("Failed to attach shared memory segment");

        $this->data_start = $this->ffi->cast("char *", $this->shm_addr) + (2 * $this->int_size);

        $this->_id = [$key,$shm_id];
        echo "\n\n=== SHMOP @ ".__CLASS__." / {$key} / {$shm_id}";
    }

    public function get_length(): int
    {
        return $this->max_rows;
    }

    protected function read_cell(int $row, $column)
    {
        $col_index = is_string($column) ? $this->column_map[$column] : $column;

        if ($col_index < 0 || $col_index >= count($this->columns))
            throw new InvalidArgumentException("Invalid column: $column");

        $offset = array_sum(array_slice($this->columns, 0, $col_index));
        $read_pos = $this->data_start + ($row * $this->row_size) + $offset;

        return rtrim(FFI::string($read_pos, $this->columns[$col_index]), "\0");
    }

    protected function write_cell(int $row, $column, string $value)
    {
        $col_index = is_string($column) ? $this->column_map[$column] : $column;

        if ($col_index < 0 || $col_index >= count($this->columns))
            throw new InvalidArgumentException("Invalid column: $column");

        $offset = array_sum(array_slice($this->columns, 0, $col_index));
        $write_pos = $this->data_start + ($row * $this->row_size) + $offset;

        $this->ffi->memcpy($write_pos, str_pad(substr($value, 0, $this->columns[$col_index]), $this->columns[$col_index], "\0"), $this->columns[$col_index]);
    }

    protected function write_row(array $row_data, int $row_index)
    {
        if (count($row_data) !== count($this->columns))
            throw new \InvalidArgumentException("Row data count does not match column count");

        $write_pos = $this->data_start + ($row_index * $this->row_size);
        $row_buffer = $this->ffi->new("char[{$this->row_size}]");
        $offset = 0;

        foreach ($this->columns as $col_index => $width)
        {
            $value = (string)($row_data[$col_index] ?? '');
            $padded_value = str_pad(substr($value, 0, $width), $width, "\0");

            $this->ffi->memcpy($row_buffer + $offset, $padded_value, $width);
            $offset += $width;
        }

        $this->ffi->memcpy($write_pos, $row_buffer, $this->row_size);
    }

    protected function read_row(int $row_index): array
    {
        if ($row_index < 0 || $row_index >= $this->max_rows)
            throw new \InvalidArgumentException("Invalid row index");

        $read_pos = $this->data_start + ($row_index * $this->row_size);
        $row_data = $this->ffi->new("char[{$this->row_size}]");

        $this->ffi->memcpy($row_data, $read_pos, $this->row_size);

        $result = [];
        $offset = 0;
        foreach( $this->columns as $col_index => $width )
        {
            $result[$col_index] = rtrim(FFI::string($row_data + $offset, $width), "\0");
            $offset += $width;
        }

        return $result;
    }

    protected function read_column($column, int $start_row, int $length, bool $reverse = false): array
    {
        $col_index = is_string($column) ? $this->column_map[$column] ?? null : $column;

        if ($col_index === null || $col_index < 0 || $col_index >= count($this->columns))
            throw new \InvalidArgumentException("Invalid column: $column");
    
        $column_width = $this->columns[$col_index];
        $column_offset = array_sum(array_slice($this->columns, 0, $col_index));
    
        $result = [];
        $read_buffer = $this->ffi->new("char[$column_width]");
    
        for( $i = 0; $i < $length; $i++ )
        {
            $row_index = $reverse
                ? ($start_row - $i + $this->max_rows) % $this->max_rows
                : ($start_row + $i) % $this->max_rows;
    
            $read_pos = $this->ffi->cast("char *", $this->data_start) + ($row_index * $this->row_size) + $column_offset;
            $this->ffi->memcpy($read_buffer, $read_pos, $column_width);
            $result[] = rtrim(FFI::string($read_buffer, $column_width), "\0");
        }
    
        return $result;
    }

    public function __destruct()
    {
        echo "\n--Taking down deq ".implode('-',$this->_id)."\n";
        $this->ffi->shmdt($this->shm_addr);
    }
}


class shmop_deq extends shmop_table_base implements ArrayAccess
{
    public int $shm_key = 0;

    private $head;
    private $tail;


    public function __construct( int $key,array $cols,int $max_rows )
    {
        parent::__construct($key, $cols, $max_rows);

        $this->shm_key = $key;

        $this->head = $this->ffi->cast("int *", $this->shm_addr);
        $this->tail = $this->ffi->cast("int *", $this->ffi->cast("char *", $this->shm_addr) + $this->int_size);

        if( $this->head[0] == 0 && $this->tail[0] == 0 )
            $this->head[0] = $this->tail[0] = 0;
    }
    
    public function push_row(array $row_data)
    {
        foreach( $row_data as $row )
        {
            $this->write_row($row, $this->tail[0]);
    
            $this->tail[0] = ($this->tail[0] + 1) % $this->max_rows;
    
            if ($this->tail[0] == $this->head[0])
                $this->head[0] = ($this->head[0] + 1) % $this->max_rows;    
        }
    }

    // better tail_row?
    public function head_row(): array|null
    {
        if ($this->head[0] == $this->tail[0])
            return null; // Empty deque

        $row_data = array_combine(
            array_keys($this->columns),
            array_map(fn($column) => $this->read_cell($this->head[0], $column), array_keys($this->columns))
        );

//        $this->head[0] = ($this->head[0] + 1) % $this->max_rows;
        return $row_data;
    }

    public function column( $column, bool $n2o = true, ?int $length = null ): array
    {
        $count = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
        $length = min($length ?? $count, $count);

        if ($length == 0)
            return [];

        $start_row = $n2o ? ($this->tail[0] - 1 + $this->max_rows) % $this->max_rows : $this->head[0];

        return $this->read_column($column, $start_row, $length, $n2o);
    }

    public function sample(int $n): self
    {
        $count = ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
        if (abs($n) > $count)
            throw new \InvalidArgumentException("Sample size cannot exceed available data");

        $sampled = new self(0, $this->columns, $this->max_rows);
        $sampled->shm_addr = $this->shm_addr;
        $sampled->data_start = $this->data_start;

        if ($n > 0) {
            $sampled->head[0] = ($this->tail[0] - $n + $this->max_rows) % $this->max_rows;
            $sampled->tail[0] = $this->tail[0];
        } else {
            $sampled->head[0] = $this->head[0];
            $sampled->tail[0] = ($this->head[0] - $n) % $this->max_rows;
        }

        return $sampled;
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool
    {
        if (is_array($offset) && count($offset) == 2) {
            [$column, $row] = $offset;
            return isset($this->columns[$column]) && 
                   $row >= 0 && 
                   $row < ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
        }
        return is_int($offset) && 
               $offset >= 0 && 
               $offset < ($this->tail[0] - $this->head[0] + $this->max_rows) % $this->max_rows;
    }

    public function offsetGet($offset): mixed
    {
        if (!$this->offsetExists($offset))
            throw new \OutOfBoundsException("Invalid offset");

        if (is_array($offset) && count($offset) == 2) {
            [$column, $row] = $offset;
            $actual_row = ($this->head[0] + $row) % $this->max_rows;
            return $this->read_cell($actual_row, $column);
        }

        $row_data = [];
        $actual_row = ($this->head[0] + $offset) % $this->max_rows;
        foreach ($this->columns as $column => $width)
            $row_data[$column] = $this->read_cell($actual_row, $column);

        return $row_data;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \RuntimeException("shmop_deq is read-only through ArrayAccess");
    }

    public function offsetUnset($offset): void
    {
        throw new \RuntimeException("Cannot unset values in shmop_deq");
    }
    
    public function __destruct()
    {
        parent::__destruct();
    }
}


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



