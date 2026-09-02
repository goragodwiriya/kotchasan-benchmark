-- Benchmark schema (TechEmpower-style)
-- The docker-entrypoint mysql client may default to latin1; force UTF-8
-- so multibyte fortune rows are stored correctly.
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS benchmark
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE benchmark;

CREATE TABLE world (
    id INT NOT NULL PRIMARY KEY,
    randomNumber INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fortune (
    id INT NOT NULL PRIMARY KEY,
    message VARCHAR(2048) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO fortune (id, message) VALUES
    (1,  'fortune: No such file or directory'),
    (2,  'A computer scientist is someone who fixes things that aren''t broken.'),
    (3,  'It is easier to write an incorrect program than understand a correct one.'),
    (4,  'Given enough eyeballs, all bugs are shallow.'),
    (5,  'Computers make very fast, very accurate mistakes.'),
    (6,  '<script>alert("This should be escaped as HTML entities!");</script>'),
    (7,  'ฉันสามารถกินแก้วและมันไม่ทำร้ายฉัน (multibyte row for UTF-8 correctness)'),
    (8,  ' feature: A bug with seniority and legal representation.'),
    (9,  'Frameworks do not write bugs, programmers write bugs.'),
    (10, 'Proofreading is more effective after publication.'),
    (11, 'A chat with a Russian developer: better latency than with an American one.'),
    (12, 'It''s not the hardware that''s slow, it''s your framework.');
