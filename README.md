FileWatcher
-------------
Pequeño script que vigila cambios en los archivos de una carpeta y captura los eventos segun se presenten. 

**Hecho con:** reactPHP, Idiorm, Monolog y amors

 - ReactPHP
 - inotify - monitoring file system events on linux
 - Idiorm
 - Monolog
 - amors :3

> **Como correrlo, en una terminal en linux con php y composer :**
> 
> - `composer install`
> - `composer update`
> - dar permisos al archivo con: `chmod 755 fileWatcher.php`
> - para ejecutarlo normalmente: `./fileWatcher.php`
> - para mandarlo a segundo plano y que se mantenga ejecutando: 
> `nohup ./fileWatcher.php &`
> - para finalizar el proceso en segundo plano: `ps aux --sort -rss` 
> - con el PID del proceso del comando anterior: `kill PID`
