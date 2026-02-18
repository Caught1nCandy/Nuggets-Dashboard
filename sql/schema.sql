-- Initial schema file
-- Tables will be added here
-- INFS 4050 Database Creation
CREATE DATABASE IF NOT EXISTS dashboard_prod;
USE dashboard_prod ;

CREATE TABLE IF NOT EXISTS `role` (
	role_id VARCHAR (20) ,
	manager_id VARCHAR (20) NOT NULL UNIQUE,
	manager_name VARCHAR (255),
	director_id VARCHAR (20) NOT NULL UNIQUE,
	director_name VARCHAR (255),
	vp_id VARCHAR (20) NOT NULL UNIQUE,
	vp_name VARCHAR (255),
	svp_id VARCHAR (20) NOT NULL UNIQUE,
	svp_name VARCHAR (255) ,
	CONSTRAINT pk_role PRIMARY KEY (role_id)
) ;

CREATE TABLE IF NOT EXISTS job (
	job_code VARCHAR (20) ,
	job_type VARCHAR (255) NOT NULL,
	CONSTRAINT pk_job PRIMARY KEY (job_code)
) ;

CREATE TABLE IF NOT EXISTS `organization` (
	organization_id VARCHAR (20) ,
	organization_name VARCHAR (255) NOT NULL,
	CONSTRAINT pk_organization PRIMARY KEY (organization_id)
) ;

CREATE TABLE IF NOT EXISTS workforce ( 
	employee_id VARCHAR (20) ,
	first_name VARCHAR (255) NOT NULL,
	last_name VARCHAR (255) NOT NULL,
	job_code VARCHAR (20) ,
	pay_band VARCHAR (255) NOT NULL,
	tenure INT NOT NULL,
	anniversary DATE NOT NULL,
	birthday DATE NOT NULL,
	work_city VARCHAR (255) NOT NULL,
	state CHAR (2) NOT NULL,
	work_postal VARCHAR (10) NOT NULL, 
	organization_id VARCHAR (20) ,
	manager_id VARCHAR (20) ,
	director_id VARCHAR (20) ,
	vp_id VARCHAR (20) ,
	svp_id VARCHAR (20),
	CONSTRAINT pk_workforce PRIMARY KEY (employee_id),
	CONSTRAINT fk1_workforce FOREIGN KEY (job_code) REFERENCES job (job_code),
	CONSTRAINT fk2_workforce FOREIGN KEY (organization_id) REFERENCES `organization` (organization_id),
	CONSTRAINT fk3_workforce FOREIGN KEY (manager_id) REFERENCES `role` (manager_id),
	CONSTRAINT fk4_workforce FOREIGN KEY (director_id) REFERENCES `role` (director_id),
	CONSTRAINT fk5_workforce FOREIGN KEY (vp_id) REFERENCES `role` (vp_id),
	CONSTRAINT fk6_workforce FOREIGN KEY (svp_id) REFERENCES `role` (svp_id)
) ; 


CREATE TABLE IF NOT EXISTS login (
	username VARCHAR (255) ,
	`password` VARCHAR (255) NOT NULL,
	employee_id VARCHAR (20) NOT NULL,
	CONSTRAINT pk_login PRIMARY KEY (username),
	CONSTRAINT fk1_login FOREIGN KEY (employee_id) REFERENCES workforce (employee_id)
) ;
