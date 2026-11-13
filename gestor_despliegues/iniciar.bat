@echo off
cd /d "%~dp0"
php -d post_max_size=256M -d memory_limit=1024M -d max_execution_time=0 -S localhost:8473 router.php
