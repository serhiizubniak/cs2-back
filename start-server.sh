#!/bin/bash
echo "Starting PHP server on http://localhost:8000"
echo "API will be available at http://localhost:8000/api/"
php -S localhost:8000 router.php
