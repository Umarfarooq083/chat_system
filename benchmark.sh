#!/bin/bash
# Laravel Octane Performance Benchmark Script
# Usage: ./benchmark.sh

# CONFIGURATION
URL="http://127.0.0.1:8000/login"   # Test URL
REQUESTS=500                         # Total requests
CONCURRENCY=50                       # Concurrent requests

echo "========================================"
echo "Laravel Performance Benchmark"
echo "URL: $URL"
echo "Requests: $REQUESTS, Concurrency: $CONCURRENCY"
echo "========================================"

# Function to run benchmark
run_benchmark() {
    local server_name=$1
    echo
    echo "------ Testing: $server_name ------"
    ab -n $REQUESTS -c $CONCURRENCY $URL | grep "Requests per second\|Time per request\|Failed requests"
    echo "----------------------------------"
}

# Step 1: Normal PHP-FPM / Artisan Serve
echo
echo "Step 1: Testing normal PHP-FPM / php artisan serve"
echo "Start your server manually: php artisan serve"
echo "Press [ENTER] after the server is running..."
read

run_benchmark "Normal PHP-FPM / Artisan Serve"

# Step 2: Octane (Swoole)
echo
echo "Step 2: Testing Laravel Octane (Swoole)"
echo "Start Octane server in another terminal:"
echo "php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000"
echo "Press [ENTER] after Octane server is running..."
read

run_benchmark "Laravel Octane (Swoole)"

echo
echo "Benchmark Complete!"
echo "Compare Requests/sec and Time/request above to see performance improvement."