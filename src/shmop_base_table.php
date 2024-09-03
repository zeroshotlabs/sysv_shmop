<?php declare(strict_types=1);
namespace stackware\shmdeq;

use \InvalidArgumentException;
use \Exception;
use \FFI;
use \FFI\Cdata as cdata;

// need to also use libffi traits
use function stackware\libffi\plog;
use function stackware\libffi\to_eights;


abstract class shmop_table_base
{
    public const IPC_CREAT = 01000;

    public array $_id = [];

    public $ffi;
    public cdata $shm_addr;
    public int $max_rows;
    public array $columns;
    public array $column_map;
    public int $row_count;
    public int $row_size;
    public cdata $data_start;
    public int $int_size;

    
    public function __construct( int $key, array $column_structure, int $max_rows )
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
        $this->columns = $column_structure;
        $this->column_map = array_flip(array_keys($column_structure));
        $this->row_size = to_eights(array_sum($this->columns));

        $total_size = ($this->row_size * $max_rows) + (2 * $this->int_size);
        $total_size = to_eights($total_size);

        $shm_id = $this->ffi->shmget($key, $total_size, 0666 | self::IPC_CREAT);

        if ($shm_id === -1)
            throw new RuntimeException("Failed to get shared memory segment: ".$this->ffi->errno);

        $this->shm_addr = $this->ffi->shmat($shm_id, NULL, 0);

        if ($this->shm_addr === $this->ffi->cast("void *", -1))
            throw new RuntimeException("Failed to attach shared memory segment");

        $this->data_start = $this->ffi->cast("char *", $this->shm_addr) + (2 * $this->int_size);

        $this->_id = [$key,$shm_id];
        plog("SHMOP @ ".implode('|',array_keys($this->columns))." / {$key} / {$shm_id}");
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

    protected function write_row( int $row_index,array $row_data )
    {
        if (count($row_data) !== count($this->columns))
        {
            $msg = "Incoming columns don't match configured columns - see log.";
            plog("$msg \n\nincoming: ".print_r(array_keys($row_data),true)."\n\n"
                         ." columns: ".print_r(array_keys($this->columns),true));

            throw new InvalidArgumentException($msg);
        }

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
            throw new InvalidArgumentException("Invalid row index");

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
            throw new InvalidArgumentException("Invalid column: $column");
    
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

