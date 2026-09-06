<?php

/**
 * Real questions and gold SQL from the Spider dev set, database concert_singer.
 *
 * Committed so the benchmark runs offline and reproducibly. Spider does not
 * distribute its SQLite files through this channel, so the tables are created
 * from the published schema and filled with synthetic rows -  see
 * SpiderSampleTest for exactly what that lets the number be compared against,
 * and what it does not.
 *
 * Questions: 45
 */

return [
    0 => [
        'question' => 'How many singers do we have?',
        'gold' => 'SELECT count(*) FROM singer',
    ],
    1 => [
        'question' => 'What is the total number of singers?',
        'gold' => 'SELECT count(*) FROM singer',
    ],
    2 => [
        'question' => 'Show name, country, age for all singers ordered by age from the oldest to the youngest.',
        'gold' => 'SELECT name , country , age FROM singer ORDER BY age DESC',
    ],
    3 => [
        'question' => 'What are the names, countries, and ages for every singer in descending order of age?',
        'gold' => 'SELECT name , country , age FROM singer ORDER BY age DESC',
    ],
    4 => [
        'question' => 'What is the average, minimum, and maximum age of all singers from France?',
        'gold' => 'SELECT avg(age) , min(age) , max(age) FROM singer WHERE country = \'France\'',
    ],
    5 => [
        'question' => 'What is the average, minimum, and maximum age for all French singers?',
        'gold' => 'SELECT avg(age) , min(age) , max(age) FROM singer WHERE country = \'France\'',
    ],
    6 => [
        'question' => 'Show the name and the release year of the song by the youngest singer.',
        'gold' => 'SELECT song_name , song_release_year FROM singer ORDER BY age LIMIT 1',
    ],
    7 => [
        'question' => 'What are the names and release years for all the songs of the youngest singer?',
        'gold' => 'SELECT song_name , song_release_year FROM singer ORDER BY age LIMIT 1',
    ],
    8 => [
        'question' => 'What are all distinct countries where singers above age 20 are from?',
        'gold' => 'SELECT DISTINCT country FROM singer WHERE age > 20',
    ],
    9 => [
        'question' => 'What are  the different countries with singers above age 20?',
        'gold' => 'SELECT DISTINCT country FROM singer WHERE age > 20',
    ],
    10 => [
        'question' => 'Show all countries and the number of singers in each country.',
        'gold' => 'SELECT country , count(*) FROM singer GROUP BY country',
    ],
    11 => [
        'question' => 'How many singers are from each country?',
        'gold' => 'SELECT country , count(*) FROM singer GROUP BY country',
    ],
    12 => [
        'question' => 'List all song names by singers above the average age.',
        'gold' => 'SELECT song_name FROM singer WHERE age > (SELECT avg(age) FROM singer)',
    ],
    13 => [
        'question' => 'What are all the song names by singers who are older than average?',
        'gold' => 'SELECT song_name FROM singer WHERE age > (SELECT avg(age) FROM singer)',
    ],
    14 => [
        'question' => 'Show location and name for all stadiums with a capacity between 5000 and 10000.',
        'gold' => 'SELECT LOCATION , name FROM stadium WHERE capacity BETWEEN 5000 AND 10000',
    ],
    15 => [
        'question' => 'What are the locations and names of all stations with capacity between 5000 and 10000?',
        'gold' => 'SELECT LOCATION , name FROM stadium WHERE capacity BETWEEN 5000 AND 10000',
    ],
    16 => [
        'question' => 'What is the maximum capacity and the average of all stadiums ?',
        'gold' => 'select max(capacity), average from stadium',
    ],
    17 => [
        'question' => 'What is the average and maximum capacities for all stadiums ?',
        'gold' => 'select avg(capacity) , max(capacity) from stadium',
    ],
    18 => [
        'question' => 'What is the name and capacity for the stadium with highest average attendance?',
        'gold' => 'SELECT name , capacity FROM stadium ORDER BY average DESC LIMIT 1',
    ],
    19 => [
        'question' => 'What is the name and capacity for the stadium with the highest average attendance?',
        'gold' => 'SELECT name , capacity FROM stadium ORDER BY average DESC LIMIT 1',
    ],
    20 => [
        'question' => 'How many concerts are there in year 2014 or 2015?',
        'gold' => 'SELECT count(*) FROM concert WHERE YEAR = 2014 OR YEAR = 2015',
    ],
    21 => [
        'question' => 'How many concerts occurred in 2014 or 2015?',
        'gold' => 'SELECT count(*) FROM concert WHERE YEAR = 2014 OR YEAR = 2015',
    ],
    22 => [
        'question' => 'Show the stadium name and the number of concerts in each stadium.',
        'gold' => 'SELECT T2.name , count(*) FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id GROUP BY T1.stadium_id',
    ],
    23 => [
        'question' => 'For each stadium, how many concerts play there?',
        'gold' => 'SELECT T2.name , count(*) FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id GROUP BY T1.stadium_id',
    ],
    24 => [
        'question' => 'Show the stadium name and capacity with most number of concerts in year 2014 or after.',
        'gold' => 'SELECT T2.name , T2.capacity FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.year >= 2014 GROUP BY T2.stadium_id ORDER BY count(*) DESC LIMIT 1',
    ],
    25 => [
        'question' => 'What is the name and capacity of the stadium with the most concerts after 2013 ?',
        'gold' => 'select t2.name , t2.capacity from concert as t1 join stadium as t2 on t1.stadium_id = t2.stadium_id where t1.year > 2013 group by t2.stadium_id order by count(*) desc limit 1',
    ],
    26 => [
        'question' => 'Which year has most number of concerts?',
        'gold' => 'SELECT YEAR FROM concert GROUP BY YEAR ORDER BY count(*) DESC LIMIT 1',
    ],
    27 => [
        'question' => 'What is the year that had the most concerts?',
        'gold' => 'SELECT YEAR FROM concert GROUP BY YEAR ORDER BY count(*) DESC LIMIT 1',
    ],
    28 => [
        'question' => 'Show the stadium names without any concert.',
        'gold' => 'SELECT name FROM stadium WHERE stadium_id NOT IN (SELECT stadium_id FROM concert)',
    ],
    29 => [
        'question' => 'What are the names of the stadiums without any concerts?',
        'gold' => 'SELECT name FROM stadium WHERE stadium_id NOT IN (SELECT stadium_id FROM concert)',
    ],
    30 => [
        'question' => 'Show countries where a singer above age 40 and a singer below 30 are from.',
        'gold' => 'SELECT country FROM singer WHERE age > 40 INTERSECT SELECT country FROM singer WHERE age < 30',
    ],
    31 => [
        'question' => 'Show names for all stadiums except for stadiums having a concert in year 2014.',
        'gold' => 'SELECT name FROM stadium EXCEPT SELECT T2.name FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.year = 2014',
    ],
    32 => [
        'question' => 'What are the names of all stadiums that did not have a concert in 2014?',
        'gold' => 'SELECT name FROM stadium EXCEPT SELECT T2.name FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.year = 2014',
    ],
    33 => [
        'question' => 'Show the name and theme for all concerts and the number of singers in each concert.',
        'gold' => 'SELECT T2.concert_name , T2.theme , count(*) FROM singer_in_concert AS T1 JOIN concert AS T2 ON T1.concert_id = T2.concert_id GROUP BY T2.concert_id',
    ],
    34 => [
        'question' => 'What are the names , themes , and number of singers for every concert ?',
        'gold' => 'select t2.concert_name , t2.theme , count(*) from singer_in_concert as t1 join concert as t2 on t1.concert_id = t2.concert_id group by t2.concert_id',
    ],
    35 => [
        'question' => 'List singer names and number of concerts for each singer.',
        'gold' => 'SELECT T2.name , count(*) FROM singer_in_concert AS T1 JOIN singer AS T2 ON T1.singer_id = T2.singer_id GROUP BY T2.singer_id',
    ],
    36 => [
        'question' => 'What are the names of the singers and number of concerts for each person?',
        'gold' => 'SELECT T2.name , count(*) FROM singer_in_concert AS T1 JOIN singer AS T2 ON T1.singer_id = T2.singer_id GROUP BY T2.singer_id',
    ],
    37 => [
        'question' => 'List all singer names in concerts in year 2014.',
        'gold' => 'SELECT T2.name FROM singer_in_concert AS T1 JOIN singer AS T2 ON T1.singer_id = T2.singer_id JOIN concert AS T3 ON T1.concert_id = T3.concert_id WHERE T3.year = 2014',
    ],
    38 => [
        'question' => 'What are the names of the singers who performed in a concert in 2014?',
        'gold' => 'SELECT T2.name FROM singer_in_concert AS T1 JOIN singer AS T2 ON T1.singer_id = T2.singer_id JOIN concert AS T3 ON T1.concert_id = T3.concert_id WHERE T3.year = 2014',
    ],
    39 => [
        'question' => 'what is the name and nation of the singer who have a song having \'Hey\' in its name?',
        'gold' => 'SELECT name , country FROM singer WHERE song_name LIKE \'%Hey%\'',
    ],
    40 => [
        'question' => 'What is the name and country of origin of every singer who has a song with the word \'Hey\' in its title?',
        'gold' => 'SELECT name , country FROM singer WHERE song_name LIKE \'%Hey%\'',
    ],
    41 => [
        'question' => 'Find the name and location of the stadiums which some concerts happened in the years of both 2014 and 2015.',
        'gold' => 'SELECT T2.name , T2.location FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.Year = 2014 INTERSECT SELECT T2.name , T2.location FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.Year = 2015',
    ],
    42 => [
        'question' => 'What are the names and locations of the stadiums that had concerts that occurred in both 2014 and 2015?',
        'gold' => 'SELECT T2.name , T2.location FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.Year = 2014 INTERSECT SELECT T2.name , T2.location FROM concert AS T1 JOIN stadium AS T2 ON T1.stadium_id = T2.stadium_id WHERE T1.Year = 2015',
    ],
    43 => [
        'question' => 'Find the number of concerts happened in the stadium with the highest capacity .',
        'gold' => 'select count(*) from concert where stadium_id = (select stadium_id from stadium order by capacity desc limit 1)',
    ],
    44 => [
        'question' => 'What are the number of concerts that occurred in the stadium with the largest capacity ?',
        'gold' => 'select count(*) from concert where stadium_id = (select stadium_id from stadium order by capacity desc limit 1)',
    ],
];
