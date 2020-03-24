Fontankafi.Ru export tool
-------------------------

How to run: 
```
cp _env.example _env
nano _env
...
composer install
php export.php
```

Tests
-----

http://fontankafi.ru/articles/38294/
25 инлайн-медиа (фото)
6 фоторепортажей

Routing table
-------------

http://fontankafi.ru/districts/                     -->     static
http://fontankafi.ru/districts/N                    -->     imported
http://fontankafi.ru/places/                        -->     /
http://fontankafi.ru/places/N                       -->     imported
http://fontankafi.ru/articles/                      -->     rubric
http://fontankafi.ru/articles/N                     -->     imported
http://fontankafi.ru/pages/                         -->     static ?
http://fontankafi.ru/pages/N                        -->     imported
