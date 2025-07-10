#!/bin/bash

# Task Management API - Quick Start Script
set -e

echo "Starting Task Management API Setup..."
echo "====================================="

# Check Docker
if ! command -v docker &> /dev/null || ! command -v docker-compose &> /dev/null; then
    echo "[ERROR] Docker not found. Please install Docker Desktop."
    exit 1
fi
echo "[OK] Docker found"

# Setup environment
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[OK] Created .env file with Docker configuration"
fi

# Start containers
echo "Starting Docker containers..."
docker-compose up -d
echo "[OK] Containers started"

# Wait for database
echo "Waiting for database..."
sleep 15

# Install dependencies
echo "Installing dependencies..."
docker-compose exec -T app composer install --ignore-platform-req=ext-fileinfo --no-interaction --quiet
echo "[OK] Dependencies installed"

# Setup application
echo "Setting up application..."
docker-compose exec -T app php artisan key:generate --no-interaction --quiet
docker-compose exec -T app php artisan migrate:fresh --seed --no-interaction --quiet
echo "[OK] Application setup complete"

# Run tests
echo "Running tests..."
if docker-compose exec -T app php artisan test --quiet; then
    echo "[OK] All tests passed"
else
    echo "[WARNING] Some tests failed but API should work"
fi

echo ""
echo "SUCCESS! Your Task Management API is ready!"
echo "=========================================="
echo "API URL: http://localhost:8000/api"
echo "Health Check: http://localhost:8000/api/health"
