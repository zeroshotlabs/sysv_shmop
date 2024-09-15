<?php
require_once 'src/shm_table.php';

use stackware\posix_shm\shm_table;


// $ffi = FFI::cdef(file_get_contents("src/shm_table.h"), "shm_table.so");

class TestTable extends shm_table {
    // This class is empty, it just needs to extend posix_shm_table
}

// Helper function to generate random strings
function randomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
}

// Helper function to generate random date
function randomDate() {
    $start = strtotime('1950-01-01');
    $end = strtotime('2005-12-31');
    $timestamp = mt_rand($start, $end);
    return date('Y-m-d', $timestamp);
}

// List of states and countries for random selection
$states = ['CA', 'NY', 'TX', 'FL', 'IL', 'PA', 'OH', 'GA', 'NC', 'MI'];
$countries = ['USA', 'Canada', 'UK', 'Australia', 'Germany', 'France', 'Japan', 'Brazil', 'India', 'Mexico'];

// Create table
$columns = [
    'first' => 32,
    'last' => 32,
    'dob' => 32,
    'state' => 32,
    'country' => 32
];

$table = new TestTable('/test_shm_table', 100, $columns, 0666, 0666);

// Fill table with random data
echo "Filling table with random data...\n";
for ($i = 0; $i < 100; $i++) {
    $row = [
        'first' => randomString(rand(5, 15)),
        'last' => randomString(rand(5, 15)),
        'dob' => randomDate(),
        'state' => $states[array_rand($states)],
        'country' => $countries[array_rand($countries)]
    ];
    $table->writeRow($i, $row);
}

// Read back and print data
echo "\nReading back data:\n";
echo str_pad("Row", 5) . str_pad("First", 17) . str_pad("Last", 17) . str_pad("DOB", 12) . str_pad("State", 7) . "Country\n";
echo str_repeat("-", 70) . "\n";

for ($i = 0; $i < 100; $i++) {
    $row = $table->readRow($i);
    echo str_pad($i, 5) . 
         str_pad($row['first'], 17) . 
         str_pad($row['last'], 17) . 
         str_pad($row['dob'], 12) . 
         str_pad($row['state'], 7) . 
         $row['country'] . "\n";
}

echo "\nTest completed.\n";