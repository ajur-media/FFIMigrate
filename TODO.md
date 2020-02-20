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
