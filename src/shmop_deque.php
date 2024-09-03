<?php declare(strict_types=1);
namespace stackware\shmdeq;

use \Closure;
use \ArrayAccess;
use \RuntimeException;
use \OutOfBoundsException;

use function stackware\libffi\plog;



class shmop_deque extends shmop_table_base implements ArrayAccess
{
    private $head;
    private $tail;

    public int $shm_key = 0;

    public Closure $write_row_cb;


    public function __construct( int $key,array $cols,int $max_rows )
    {
        parent::__construct($key, $cols, $max_rows);

        $this->shm_key = $key;

        $this->head = $this->ffi->cast("int *", $this->shm_addr);
        $this->tail = $this->ffi->cast("int *", $this->ffi->cast("char *", $this->shm_addr) + $this->int_size);

        if( $this->head[0] === 0 && $this->tail[0] === 0 )
            $this->head[0] = $this->tail[0] = 0;

        $this->write_row_cb = \Closure::fromCallable(function($row) {
            $this->write_row($this->tail[0],$row);

            $this->tail[0] = ($this->tail[0] + 1) % $this->max_rows;

            if( $this->tail[0] === $this->head[0] )
                $this->head[0] = ($this->head[0] + 1) % $this->max_rows;
        });
    }

    public function push_rows( array $rows )
    {
        array_walk($rows,function( $r ) {
            plog("\n".implode("-",$r));
//            plog($r);
        });

//        array_walk($row,$this->write_row_cb);
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

        for( $i = 0; $i < $lines; $i++ )
        {
            $row_data = array_combine(
                array_keys($this->columns),
                array_map(fn($column) => $this->read_cell($current_row, $column), array_keys($this->columns))
            );

            $rows[] = $row_data;
            $current_row = ($current_row + 1) % $this->max_rows;

            if( $current_row === $this->tail[0] )
                break; // Reached the end of the deque
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
        if (!$this->offsetExists($offset))
            throw new OutOfBoundsException("Invalid offset");
    
        if (is_array($offset) && count($offset) === 2)
        {
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
    
    public function __destruct()
    {
        parent::__destruct();
    }
    public function offsetSet($offset, $value): void
    {
        throw new RuntimeException("shmop_deque is read-only through ArrayAccess");
    }

    public function offsetUnset($offset): void
    {
        throw new RuntimeException("Cannot unset values in shmop_deque");
    }
}

