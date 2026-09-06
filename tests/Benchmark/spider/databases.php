<?php

/**
 * Spider database definitions, built from the published schemas.
 *
 * Spider does not distribute its SQLite files through the channel available
 * here, so each database is created from its real schema -  real table names,
 * real column names and casing, real foreign keys -  and filled with synthetic
 * rows written to make the dev questions answerable.
 *
 * Column names are Spider's and are deliberately not tidied. Half the
 * difficulty of an unfamiliar schema is that it is not named the way you would
 * name it: `Is_male` holds 'T'/'F', `Horsepower` is text, and `car_names.Model`
 * joins to `model_list.Model` by string rather than by id.
 */

return [
    'concert_singer' => [
        'ddl' => [
            'CREATE TABLE stadium (
                Stadium_ID INTEGER PRIMARY KEY, Location TEXT, Name TEXT,
                Capacity INTEGER, Highest INTEGER, Lowest INTEGER, Average INTEGER)',
            'CREATE TABLE singer (
                Singer_ID INTEGER PRIMARY KEY, Name TEXT, Country TEXT, Song_Name TEXT,
                Song_release_year TEXT, Age INTEGER, Is_male TEXT)',
            'CREATE TABLE concert (
                concert_ID INTEGER PRIMARY KEY, concert_Name TEXT, Theme TEXT,
                Stadium_ID INTEGER, Year TEXT,
                FOREIGN KEY (Stadium_ID) REFERENCES stadium(Stadium_ID))',
            'CREATE TABLE singer_in_concert (
                concert_ID INTEGER, Singer_ID INTEGER,
                FOREIGN KEY (concert_ID) REFERENCES concert(concert_ID),
                FOREIGN KEY (Singer_ID) REFERENCES singer(Singer_ID))',
        ],
        'rows' => [
            'stadium' => [
                ['Stadium_ID' => 1, 'Location' => 'Raith Rovers', 'Name' => "Stark's Park", 'Capacity' => 10104, 'Highest' => 4812, 'Lowest' => 1294, 'Average' => 2106],
                ['Stadium_ID' => 2, 'Location' => 'Ayr United', 'Name' => 'Somerset Park', 'Capacity' => 11998, 'Highest' => 2363, 'Lowest' => 1057, 'Average' => 1477],
                ['Stadium_ID' => 3, 'Location' => 'East Fife', 'Name' => 'Bayview Stadium', 'Capacity' => 2000, 'Highest' => 1980, 'Lowest' => 533, 'Average' => 864],
                ['Stadium_ID' => 4, 'Location' => 'Queens Park', 'Name' => 'Hampden Park', 'Capacity' => 52500, 'Highest' => 1763, 'Lowest' => 466, 'Average' => 730],
                ['Stadium_ID' => 5, 'Location' => 'Stirling Albion', 'Name' => 'Forthbank Stadium', 'Capacity' => 3808, 'Highest' => 1125, 'Lowest' => 404, 'Average' => 642],
            ],
            'singer' => [
                ['Singer_ID' => 1, 'Name' => 'Joe Sharp', 'Country' => 'Netherlands', 'Song_Name' => 'You', 'Song_release_year' => '1992', 'Age' => 52, 'Is_male' => 'F'],
                ['Singer_ID' => 2, 'Name' => 'Timbaland', 'Country' => 'United States', 'Song_Name' => 'Dangerous', 'Song_release_year' => '2008', 'Age' => 32, 'Is_male' => 'T'],
                ['Singer_ID' => 3, 'Name' => 'Justin Brown', 'Country' => 'France', 'Song_Name' => 'Hey Oh', 'Song_release_year' => '2013', 'Age' => 29, 'Is_male' => 'T'],
                ['Singer_ID' => 4, 'Name' => 'Rose White', 'Country' => 'France', 'Song_Name' => 'Sun', 'Song_release_year' => '2003', 'Age' => 41, 'Is_male' => 'F'],
                ['Singer_ID' => 5, 'Name' => 'John Nizinik', 'Country' => 'France', 'Song_Name' => 'Gentleman', 'Song_release_year' => '2014', 'Age' => 43, 'Is_male' => 'T'],
                ['Singer_ID' => 6, 'Name' => 'Tribal King', 'Country' => 'France', 'Song_Name' => 'Love', 'Song_release_year' => '2016', 'Age' => 25, 'Is_male' => 'T'],
            ],
            'concert' => [
                ['concert_ID' => 1, 'concert_Name' => 'Audition Anthem', 'Theme' => 'Free choice', 'Stadium_ID' => 1, 'Year' => '2014'],
                ['concert_ID' => 2, 'concert_Name' => 'Super bootcamp', 'Theme' => 'Free choice 2', 'Stadium_ID' => 2, 'Year' => '2014'],
                ['concert_ID' => 3, 'concert_Name' => 'Home Visits', 'Theme' => 'Bleeding Love', 'Stadium_ID' => 2, 'Year' => '2015'],
                ['concert_ID' => 4, 'concert_Name' => 'Week 1', 'Theme' => 'Wide Awake', 'Stadium_ID' => 3, 'Year' => '2014'],
                ['concert_ID' => 5, 'concert_Name' => 'Week 1', 'Theme' => 'Happy Tonight', 'Stadium_ID' => 4, 'Year' => '2015'],
                ['concert_ID' => 6, 'concert_Name' => 'Week 2', 'Theme' => 'Party All Night', 'Stadium_ID' => 5, 'Year' => '2015'],
            ],
            'singer_in_concert' => [
                ['concert_ID' => 1, 'Singer_ID' => 2], ['concert_ID' => 1, 'Singer_ID' => 3],
                ['concert_ID' => 1, 'Singer_ID' => 5], ['concert_ID' => 2, 'Singer_ID' => 3],
                ['concert_ID' => 2, 'Singer_ID' => 6], ['concert_ID' => 3, 'Singer_ID' => 5],
                ['concert_ID' => 4, 'Singer_ID' => 4], ['concert_ID' => 5, 'Singer_ID' => 6],
                ['concert_ID' => 5, 'Singer_ID' => 3], ['concert_ID' => 6, 'Singer_ID' => 1],
            ],
        ],
    ],

    'car_1' => [
        'ddl' => [
            'CREATE TABLE continents (ContId INTEGER PRIMARY KEY, Continent TEXT)',
            'CREATE TABLE countries (
                CountryId INTEGER PRIMARY KEY, CountryName TEXT, Continent INTEGER,
                FOREIGN KEY (Continent) REFERENCES continents(ContId))',
            'CREATE TABLE car_makers (
                Id INTEGER PRIMARY KEY, Maker TEXT, FullName TEXT, Country TEXT,
                FOREIGN KEY (Country) REFERENCES countries(CountryId))',
            'CREATE TABLE model_list (
                ModelId INTEGER PRIMARY KEY, Maker INTEGER, Model TEXT,
                FOREIGN KEY (Maker) REFERENCES car_makers(Id))',
            'CREATE TABLE car_names (
                MakeId INTEGER PRIMARY KEY, Model TEXT, Make TEXT,
                FOREIGN KEY (Model) REFERENCES model_list(Model))',
            'CREATE TABLE cars_data (
                Id INTEGER PRIMARY KEY, MPG TEXT, Cylinders INTEGER, Edispl REAL,
                Horsepower TEXT, Weight INTEGER, Accelerate REAL, Year INTEGER,
                FOREIGN KEY (Id) REFERENCES car_names(MakeId))',
        ],
        'rows' => [
            'continents' => [
                ['ContId' => 1, 'Continent' => 'america'],
                ['ContId' => 2, 'Continent' => 'europe'],
                ['ContId' => 3, 'Continent' => 'asia'],
            ],
            'countries' => [
                ['CountryId' => 1, 'CountryName' => 'usa', 'Continent' => 1],
                ['CountryId' => 2, 'CountryName' => 'germany', 'Continent' => 2],
                ['CountryId' => 3, 'CountryName' => 'japan', 'Continent' => 3],
                ['CountryId' => 4, 'CountryName' => 'france', 'Continent' => 2],
            ],
            'car_makers' => [
                ['Id' => 1, 'Maker' => 'ford', 'FullName' => 'Ford Motor Company', 'Country' => '1'],
                ['Id' => 2, 'Maker' => 'bmw', 'FullName' => 'Bayerische Motoren Werke', 'Country' => '2'],
                ['Id' => 3, 'Maker' => 'toyota', 'FullName' => 'Toyota Motor Corporation', 'Country' => '3'],
                ['Id' => 4, 'Maker' => 'renault', 'FullName' => 'Renault Group', 'Country' => '4'],
                ['Id' => 5, 'Maker' => 'chevrolet', 'FullName' => 'Chevrolet Division', 'Country' => '1'],
            ],
            'model_list' => [
                ['ModelId' => 1, 'Maker' => 1, 'Model' => 'ford'],
                ['ModelId' => 2, 'Maker' => 2, 'Model' => 'bmw'],
                ['ModelId' => 3, 'Maker' => 3, 'Model' => 'toyota'],
                ['ModelId' => 4, 'Maker' => 4, 'Model' => 'renault'],
                ['ModelId' => 5, 'Maker' => 5, 'Model' => 'chevrolet'],
            ],
            'car_names' => [
                ['MakeId' => 1, 'Model' => 'ford', 'Make' => 'ford fiesta'],
                ['MakeId' => 2, 'Model' => 'ford', 'Make' => 'ford mustang'],
                ['MakeId' => 3, 'Model' => 'bmw', 'Make' => 'bmw x5'],
                ['MakeId' => 4, 'Model' => 'toyota', 'Make' => 'toyota corolla'],
                ['MakeId' => 5, 'Model' => 'toyota', 'Make' => 'toyota camry'],
                ['MakeId' => 6, 'Model' => 'renault', 'Make' => 'renault clio'],
                ['MakeId' => 7, 'Model' => 'chevrolet', 'Make' => 'chevrolet impala'],
                ['MakeId' => 8, 'Model' => 'chevrolet', 'Make' => 'chevrolet malibu'],
            ],
            'cars_data' => [
                ['Id' => 1, 'MPG' => '30', 'Cylinders' => 4, 'Edispl' => 1.6, 'Horsepower' => '120', 'Weight' => 2400, 'Accelerate' => 14.5, 'Year' => 1978],
                ['Id' => 2, 'MPG' => '18', 'Cylinders' => 8, 'Edispl' => 4.9, 'Horsepower' => '210', 'Weight' => 3400, 'Accelerate' => 11.0, 'Year' => 1980],
                ['Id' => 3, 'MPG' => '22', 'Cylinders' => 6, 'Edispl' => 3.0, 'Horsepower' => '250', 'Weight' => 3100, 'Accelerate' => 12.2, 'Year' => 1982],
                ['Id' => 4, 'MPG' => '35', 'Cylinders' => 4, 'Edispl' => 1.8, 'Horsepower' => '132', 'Weight' => 2200, 'Accelerate' => 15.1, 'Year' => 1979],
                ['Id' => 5, 'MPG' => '28', 'Cylinders' => 4, 'Edispl' => 2.0, 'Horsepower' => '150', 'Weight' => 2600, 'Accelerate' => 13.8, 'Year' => 1981],
                ['Id' => 6, 'MPG' => '40', 'Cylinders' => 4, 'Edispl' => 1.2, 'Horsepower' => '90', 'Weight' => 2000, 'Accelerate' => 16.4, 'Year' => 1977],
                ['Id' => 7, 'MPG' => '15', 'Cylinders' => 8, 'Edispl' => 5.7, 'Horsepower' => '220', 'Weight' => 3800, 'Accelerate' => 10.5, 'Year' => 1975],
                ['Id' => 8, 'MPG' => '24', 'Cylinders' => 6, 'Edispl' => 3.8, 'Horsepower' => '175', 'Weight' => 3000, 'Accelerate' => 12.9, 'Year' => 1983],
            ],
        ],
    ],
];
