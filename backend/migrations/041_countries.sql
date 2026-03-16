-- Migration 041: Countries table for destination country dropdown (consolidation, containers)
-- Rollback: DROP TABLE IF EXISTS countries;

CREATE TABLE
IF NOT EXISTS countries
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR
(10) NOT NULL UNIQUE,
    name VARCHAR
(100) NOT NULL,
    INDEX idx_countries_code
(code),
    INDEX idx_countries_name
(name)
);

INSERT IGNORE
INTO countries
(code, name) VALUES
('LB', 'Lebanon'),
('CN', 'China'),
('US', 'United States'),
('AE', 'United Arab Emirates'),
('SA', 'Saudi Arabia'),
('EG', 'Egypt'),
('JO', 'Jordan'),
('SY', 'Syria'),
('IQ', 'Iraq'),
('TR', 'Turkey'),
('IN', 'India'),
('VN', 'Vietnam'),
('TH', 'Thailand'),
('ID', 'Indonesia'),
('MY', 'Malaysia'),
('SG', 'Singapore'),
('HK', 'Hong Kong'),
('TW', 'Taiwan'),
('JP', 'Japan'),
('KR', 'South Korea'),
('DE', 'Germany'),
('FR', 'France'),
('GB', 'United Kingdom'),
('IT', 'Italy'),
('ES', 'Spain'),
('NL', 'Netherlands'),
('BE', 'Belgium'),
('PL', 'Poland'),
('RU', 'Russia'),
('AU', 'Australia'),
('CA', 'Canada'),
('BR', 'Brazil'),
('MX', 'Mexico'),
('ZA', 'South Africa'),
('NG', 'Nigeria'),
('KE', 'Kenya'),
('GH', 'Ghana'),
('MA', 'Morocco'),
('TN', 'Tunisia'),
('AL', 'Albania'),
('GR', 'Greece'),
('CY', 'Cyprus'),
('MT', 'Malta'),
('KW', 'Kuwait'),
('QA', 'Qatar'),
('BH', 'Bahrain'),
('OM', 'Oman'),
('YE', 'Yemen'),
('PK', 'Pakistan'),
('BD', 'Bangladesh'),
('LK', 'Sri Lanka'),
('PH', 'Philippines');
