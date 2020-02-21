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

Выделяет страницы, содержащие несколько репортажей

```
select * from articles_reports where item in (15079,26006,27063,28124,28169,28226,30646,32812,34749,35659,35664,38126)
```

http://fontankafi.ru/articles/38294/
25 инлайн-медиа (фото)
6 фоторепортажей