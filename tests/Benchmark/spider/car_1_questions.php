<?php

/**
 * Real questions and gold SQL from the Spider dev set, database car_1.
 *
 * Six tables in a five-hop chain: cars_data -> car_names -> model_list ->
 * car_makers -> countries -> continents. Committed so the run is offline and
 * reproducible; see SpiderSampleTest for what the synthetic data does and does
 * not let this number be compared against.
 *
 * Questions: 73
 */

return [
    0 => [
        'question' => 'How many continents are there?',
        'gold' => 'SELECT count(*) FROM CONTINENTS;',
    ],
    1 => [
        'question' => 'What is the number of continents?',
        'gold' => 'SELECT count(*) FROM CONTINENTS;',
    ],
    2 => [
        'question' => 'How many countries does each continent have? List the continent id, continent name and the number of countries.',
        'gold' => 'SELECT T1.ContId , T1.Continent , count(*) FROM CONTINENTS AS T1 JOIN COUNTRIES AS T2 ON T1.ContId = T2.Continent GROUP BY T1.ContId;',
    ],
    3 => [
        'question' => 'For each continent, list its id, name, and how many countries it has?',
        'gold' => 'SELECT T1.ContId , T1.Continent , count(*) FROM CONTINENTS AS T1 JOIN COUNTRIES AS T2 ON T1.ContId = T2.Continent GROUP BY T1.ContId;',
    ],
    4 => [
        'question' => 'How many countries are listed?',
        'gold' => 'SELECT count(*) FROM COUNTRIES;',
    ],
    5 => [
        'question' => 'How many countries exist?',
        'gold' => 'SELECT count(*) FROM COUNTRIES;',
    ],
    6 => [
        'question' => 'How many models does each car maker produce? List maker full name, id and the number.',
        'gold' => 'SELECT T1.FullName , T1.Id , count(*) FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker GROUP BY T1.Id;',
    ],
    7 => [
        'question' => 'What is the full name of each car maker, along with its id and how many models it produces?',
        'gold' => 'SELECT T1.FullName , T1.Id , count(*) FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker GROUP BY T1.Id;',
    ],
    8 => [
        'question' => 'Which model of the car has the minimum horsepower?',
        'gold' => 'SELECT T1.Model FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id ORDER BY T2.horsepower ASC LIMIT 1;',
    ],
    9 => [
        'question' => 'What is the model of the car with the smallest amount of horsepower?',
        'gold' => 'SELECT T1.Model FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id ORDER BY T2.horsepower ASC LIMIT 1;',
    ],
    10 => [
        'question' => 'Find the model of the car whose weight is below the average weight.',
        'gold' => 'SELECT T1.model FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T2.Weight < (SELECT avg(Weight) FROM CARS_DATA)',
    ],
    11 => [
        'question' => 'What is the model for the car with a weight smaller than the average?',
        'gold' => 'SELECT T1.model FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T2.Weight < (SELECT avg(Weight) FROM CARS_DATA)',
    ],
    12 => [
        'question' => 'Find the name of the makers that produced some cars in the year of 1970?',
        'gold' => 'SELECT DISTINCT T1.Maker FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker JOIN CAR_NAMES AS T3 ON T2.model = T3.model JOIN CARS_DATA AS T4 ON T3.MakeId = T4.id WHERE T4.year = \'1970\';',
    ],
    13 => [
        'question' => 'What is the name of the different car makers who produced a car in 1970?',
        'gold' => 'SELECT DISTINCT T1.Maker FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker JOIN CAR_NAMES AS T3 ON T2.model = T3.model JOIN CARS_DATA AS T4 ON T3.MakeId = T4.id WHERE T4.year = \'1970\';',
    ],
    14 => [
        'question' => 'Find the make and production time of the cars that were produced in the earliest year?',
        'gold' => 'SELECT T2.Make , T1.Year FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T1.Year = (SELECT min(YEAR) FROM CARS_DATA);',
    ],
    15 => [
        'question' => 'What is the maker of the carr produced in the earliest year and what year was it?',
        'gold' => 'SELECT T2.Make , T1.Year FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T1.Year = (SELECT min(YEAR) FROM CARS_DATA);',
    ],
    16 => [
        'question' => 'Which distinct car models are the produced after 1980?',
        'gold' => 'SELECT DISTINCT T1.model FROM MODEL_LIST AS T1 JOIN CAR_NAMES AS T2 ON T1.model = T2.model JOIN CARS_DATA AS T3 ON T2.MakeId = T3.id WHERE T3.year > 1980;',
    ],
    17 => [
        'question' => 'What are the different models for the cards produced after 1980?',
        'gold' => 'SELECT DISTINCT T1.model FROM MODEL_LIST AS T1 JOIN CAR_NAMES AS T2 ON T1.model = T2.model JOIN CARS_DATA AS T3 ON T2.MakeId = T3.id WHERE T3.year > 1980;',
    ],
    18 => [
        'question' => 'How many car makers are there in each continents? List the continent name and the count.',
        'gold' => 'SELECT T1.Continent , count(*) FROM CONTINENTS AS T1 JOIN COUNTRIES AS T2 ON T1.ContId = T2.continent JOIN car_makers AS T3 ON T2.CountryId = T3.Country GROUP BY T1.Continent;',
    ],
    19 => [
        'question' => 'What is the name of each continent and how many car makers are there in each one?',
        'gold' => 'SELECT T1.Continent , count(*) FROM CONTINENTS AS T1 JOIN COUNTRIES AS T2 ON T1.ContId = T2.continent JOIN car_makers AS T3 ON T2.CountryId = T3.Country GROUP BY T1.Continent;',
    ],
    20 => [
        'question' => 'Which of the countries has the most car makers? List the country name.',
        'gold' => 'SELECT T2.CountryName FROM CAR_MAKERS AS T1 JOIN COUNTRIES AS T2 ON T1.Country = T2.CountryId GROUP BY T1.Country ORDER BY Count(*) DESC LIMIT 1;',
    ],
    21 => [
        'question' => 'What is the name of the country with the most car makers?',
        'gold' => 'SELECT T2.CountryName FROM CAR_MAKERS AS T1 JOIN COUNTRIES AS T2 ON T1.Country = T2.CountryId GROUP BY T1.Country ORDER BY Count(*) DESC LIMIT 1;',
    ],
    22 => [
        'question' => 'How many car models are produced by each maker ? Only list the count and the maker full name .',
        'gold' => 'select count(*) , t2.fullname from model_list as t1 join car_makers as t2 on t1.maker = t2.id group by t2.id;',
    ],
    23 => [
        'question' => 'What is the number of car models that are produced by each maker and what is the id and full name of each maker?',
        'gold' => 'SELECT Count(*) , T2.FullName , T2.id FROM MODEL_LIST AS T1 JOIN CAR_MAKERS AS T2 ON T1.Maker = T2.Id GROUP BY T2.id;',
    ],
    24 => [
        'question' => 'What is the accelerate of the car make amc hornet sportabout (sw)?',
        'gold' => 'SELECT T1.Accelerate FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T2.Make = \'amc hornet sportabout (sw)\';',
    ],
    25 => [
        'question' => 'How much does the car accelerate that makes amc hornet sportabout (sw)?',
        'gold' => 'SELECT T1.Accelerate FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T2.Make = \'amc hornet sportabout (sw)\';',
    ],
    26 => [
        'question' => 'How many car makers are there in france?',
        'gold' => 'SELECT count(*) FROM CAR_MAKERS AS T1 JOIN COUNTRIES AS T2 ON T1.Country = T2.CountryId WHERE T2.CountryName = \'france\';',
    ],
    27 => [
        'question' => 'What is the number of makers of care in France?',
        'gold' => 'SELECT count(*) FROM CAR_MAKERS AS T1 JOIN COUNTRIES AS T2 ON T1.Country = T2.CountryId WHERE T2.CountryName = \'france\';',
    ],
    28 => [
        'question' => 'How many car models are produced in the usa?',
        'gold' => 'SELECT count(*) FROM MODEL_LIST AS T1 JOIN CAR_MAKERS AS T2 ON T1.Maker = T2.Id JOIN COUNTRIES AS T3 ON T2.Country = T3.CountryId WHERE T3.CountryName = \'usa\';',
    ],
    29 => [
        'question' => 'What is the count of the car models produced in the United States?',
        'gold' => 'SELECT count(*) FROM MODEL_LIST AS T1 JOIN CAR_MAKERS AS T2 ON T1.Maker = T2.Id JOIN COUNTRIES AS T3 ON T2.Country = T3.CountryId WHERE T3.CountryName = \'usa\';',
    ],
    30 => [
        'question' => 'What is the average miles per gallon(mpg) of the cars with 4 cylinders?',
        'gold' => 'SELECT avg(mpg) FROM CARS_DATA WHERE Cylinders = 4;',
    ],
    31 => [
        'question' => 'What is the average miles per gallon of all the cards with 4 cylinders?',
        'gold' => 'SELECT avg(mpg) FROM CARS_DATA WHERE Cylinders = 4;',
    ],
    32 => [
        'question' => 'What is the smallest weight of the car produced with 8 cylinders on 1974 ?',
        'gold' => 'select min(weight) from cars_data where cylinders = 8 and year = 1974',
    ],
    33 => [
        'question' => 'What is the minimum weight of the car with 8 cylinders produced in 1974 ?',
        'gold' => 'select min(weight) from cars_data where cylinders = 8 and year = 1974',
    ],
    34 => [
        'question' => 'What are all the makers and models?',
        'gold' => 'SELECT Maker , Model FROM MODEL_LIST;',
    ],
    35 => [
        'question' => 'What are the makers and models?',
        'gold' => 'SELECT Maker , Model FROM MODEL_LIST;',
    ],
    36 => [
        'question' => 'What are the countries having at least one car maker? List name and id.',
        'gold' => 'SELECT T1.CountryName , T1.CountryId FROM COUNTRIES AS T1 JOIN CAR_MAKERS AS T2 ON T1.CountryId = T2.Country GROUP BY T1.CountryId HAVING count(*) >= 1;',
    ],
    37 => [
        'question' => 'What are the names and ids of all countries with at least one car maker?',
        'gold' => 'SELECT T1.CountryName , T1.CountryId FROM COUNTRIES AS T1 JOIN CAR_MAKERS AS T2 ON T1.CountryId = T2.Country GROUP BY T1.CountryId HAVING count(*) >= 1;',
    ],
    38 => [
        'question' => 'What is the number of the cars with horsepower more than 150?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE horsepower > 150;',
    ],
    39 => [
        'question' => 'What is the number of cars with a horsepower greater than 150?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE horsepower > 150;',
    ],
    40 => [
        'question' => 'What is the average weight of cars each year?',
        'gold' => 'SELECT avg(Weight) , YEAR FROM CARS_DATA GROUP BY YEAR;',
    ],
    41 => [
        'question' => 'What is the average weight and year for each year?',
        'gold' => 'SELECT avg(Weight) , YEAR FROM CARS_DATA GROUP BY YEAR;',
    ],
    42 => [
        'question' => 'Which countries in europe have at least 3 car manufacturers?',
        'gold' => 'SELECT T1.CountryName FROM COUNTRIES AS T1 JOIN CONTINENTS AS T2 ON T1.Continent = T2.ContId JOIN CAR_MAKERS AS T3 ON T1.CountryId = T3.Country WHERE T2.Continent = \'europe\' GROUP BY T1.CountryName HAVING count(*) >= 3;',
    ],
    43 => [
        'question' => 'What are the names of all European countries with at least 3 manufacturers?',
        'gold' => 'SELECT T1.CountryName FROM COUNTRIES AS T1 JOIN CONTINENTS AS T2 ON T1.Continent = T2.ContId JOIN CAR_MAKERS AS T3 ON T1.CountryId = T3.Country WHERE T2.Continent = \'europe\' GROUP BY T1.CountryName HAVING count(*) >= 3;',
    ],
    44 => [
        'question' => 'What is the maximum horsepower and the make of the car models with 3 cylinders?',
        'gold' => 'SELECT T2.horsepower , T1.Make FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T2.cylinders = 3 ORDER BY T2.horsepower DESC LIMIT 1;',
    ],
    45 => [
        'question' => 'What is the largest amount of horsepower for the models with 3 cylinders and what make is it?',
        'gold' => 'SELECT T2.horsepower , T1.Make FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T2.cylinders = 3 ORDER BY T2.horsepower DESC LIMIT 1;',
    ],
    46 => [
        'question' => 'Which model saves the most gasoline? That is to say, have the maximum miles per gallon.',
        'gold' => 'SELECT T1.Model FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id ORDER BY T2.mpg DESC LIMIT 1;',
    ],
    47 => [
        'question' => 'What is the car model with the highest mpg ?',
        'gold' => 'select t1.model from car_names as t1 join cars_data as t2 on t1.makeid = t2.id order by t2.mpg desc limit 1;',
    ],
    48 => [
        'question' => 'What is the average horsepower of the cars before 1980?',
        'gold' => 'SELECT avg(horsepower) FROM CARS_DATA WHERE YEAR < 1980;',
    ],
    49 => [
        'question' => 'What is the average horsepower for all cars produced before 1980 ?',
        'gold' => 'select avg(horsepower) from cars_data where year < 1980;',
    ],
    50 => [
        'question' => 'What is the average edispl of the cars of model volvo?',
        'gold' => 'SELECT avg(T2.edispl) FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T1.Model = \'volvo\';',
    ],
    51 => [
        'question' => 'What is the average edispl for all volvos?',
        'gold' => 'SELECT avg(T2.edispl) FROM CAR_NAMES AS T1 JOIN CARS_DATA AS T2 ON T1.MakeId = T2.Id WHERE T1.Model = \'volvo\';',
    ],
    52 => [
        'question' => 'What is the maximum accelerate for different number of cylinders?',
        'gold' => 'SELECT max(Accelerate) , Cylinders FROM CARS_DATA GROUP BY Cylinders;',
    ],
    53 => [
        'question' => 'What is the maximum accelerate for all the different cylinders?',
        'gold' => 'SELECT max(Accelerate) , Cylinders FROM CARS_DATA GROUP BY Cylinders;',
    ],
    54 => [
        'question' => 'Which model has the most version(make) of cars?',
        'gold' => 'SELECT Model FROM CAR_NAMES GROUP BY Model ORDER BY count(*) DESC LIMIT 1;',
    ],
    55 => [
        'question' => 'What model has the most different versions?',
        'gold' => 'SELECT Model FROM CAR_NAMES GROUP BY Model ORDER BY count(*) DESC LIMIT 1;',
    ],
    56 => [
        'question' => 'How many cars have more than 4 cylinders?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE Cylinders > 4;',
    ],
    57 => [
        'question' => 'What is the number of cars with more than 4 cylinders?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE Cylinders > 4;',
    ],
    58 => [
        'question' => 'how many cars were produced in 1980?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE YEAR = 1980;',
    ],
    59 => [
        'question' => 'In 1980, how many cars were made?',
        'gold' => 'SELECT count(*) FROM CARS_DATA WHERE YEAR = 1980;',
    ],
    60 => [
        'question' => 'How many car models were produced by the maker with full name American Motor Company?',
        'gold' => 'SELECT count(*) FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker WHERE T1.FullName = \'American Motor Company\';',
    ],
    61 => [
        'question' => 'What is the number of car models created by the car maker American Motor Company?',
        'gold' => 'SELECT count(*) FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker WHERE T1.FullName = \'American Motor Company\';',
    ],
    62 => [
        'question' => 'Which makers designed more than 3 car models? List full name and the id.',
        'gold' => 'SELECT T1.FullName , T1.Id FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker GROUP BY T1.Id HAVING count(*) > 3;',
    ],
    63 => [
        'question' => 'What are the names and ids of all makers with more than 3 models?',
        'gold' => 'SELECT T1.FullName , T1.Id FROM CAR_MAKERS AS T1 JOIN MODEL_LIST AS T2 ON T1.Id = T2.Maker GROUP BY T1.Id HAVING count(*) > 3;',
    ],
    64 => [
        'question' => 'Which distinctive models are produced by maker with the full name General Motors or weighing more than 3500?',
        'gold' => 'SELECT DISTINCT T2.Model FROM CAR_NAMES AS T1 JOIN MODEL_LIST AS T2 ON T1.Model = T2.Model JOIN CAR_MAKERS AS T3 ON T2.Maker = T3.Id JOIN CARS_DATA AS T4 ON T1.MakeId = T4.Id WHERE T3.FullName = \'General Motors\' OR T4.weight > 3500;',
    ],
    65 => [
        'question' => 'What are the different models created by either the car maker General Motors or weighed more than 3500?',
        'gold' => 'SELECT DISTINCT T2.Model FROM CAR_NAMES AS T1 JOIN MODEL_LIST AS T2 ON T1.Model = T2.Model JOIN CAR_MAKERS AS T3 ON T2.Maker = T3.Id JOIN CARS_DATA AS T4 ON T1.MakeId = T4.Id WHERE T3.FullName = \'General Motors\' OR T4.weight > 3500;',
    ],
    66 => [
        'question' => 'In which years cars were produced weighing no less than 3000 and no more than 4000 ?',
        'gold' => 'select distinct year from cars_data where weight between 3000 and 4000;',
    ],
    67 => [
        'question' => 'What are the different years in which there were cars produced that weighed less than 4000 and also cars that weighted more than 3000 ?',
        'gold' => 'select distinct year from cars_data where weight between 3000 and 4000;',
    ],
    68 => [
        'question' => 'What is the horsepower of the car with the largest accelerate?',
        'gold' => 'SELECT T1.horsepower FROM CARS_DATA AS T1 ORDER BY T1.accelerate DESC LIMIT 1;',
    ],
    69 => [
        'question' => 'What is the horsepower of the car with the greatest accelerate?',
        'gold' => 'SELECT T1.horsepower FROM CARS_DATA AS T1 ORDER BY T1.accelerate DESC LIMIT 1;',
    ],
    70 => [
        'question' => 'For model volvo, how many cylinders does the car with the least accelerate have?',
        'gold' => 'SELECT T1.cylinders FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T2.Model = \'volvo\' ORDER BY T1.accelerate ASC LIMIT 1;',
    ],
    71 => [
        'question' => 'For a volvo model, how many cylinders does the version with least accelerate have?',
        'gold' => 'SELECT T1.cylinders FROM CARS_DATA AS T1 JOIN CAR_NAMES AS T2 ON T1.Id = T2.MakeId WHERE T2.Model = \'volvo\' ORDER BY T1.accelerate ASC LIMIT 1;',
    ],
    72 => [
        'question' => 'How many cars have a larger accelerate than the car with the largest horsepower?',
        'gold' => 'SELECT COUNT(*) FROM CARS_DATA WHERE Accelerate > ( SELECT Accelerate FROM CARS_DATA ORDER BY Horsepower DESC LIMIT 1 );',
    ],
];
