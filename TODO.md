1. Фоторепортажи

```
media: {
  reports: [ 
   _ : 2, 
   1 : [ 555, 666 ],
   2 : [ 777, 888 ]
  ]
}
```
и их экспорт с медиа-коллекцию

===
```
SELECT item, num_usage from (
  SELECT item , count(bind) as num_usage FROM articles_reports GROUP BY item
) subquery
WHERE num_usage > 1
```

Выделяет страницы, содержащие несколько репортажей

```
select * from articles_reports where item in (15079,26006,27063,28124,28169,28226,30646,32812,34749,35659,35664,38126)
```

http://fontankafi.ru/articles/38294/
25 инлайн-медиа (фото)
6 фоторепортажей



