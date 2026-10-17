-- OpokuAuctions local dev databases
-- Run once as your Postgres superuser:
--   psql -h 127.0.0.1 -d postgres -f database/setup-databases.sql
--
-- Creates a dedicated role (least privilege, separate from your personal
-- Postgres.app account) plus one database per spike so the two builds stay
-- comparable but isolated.

DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'opoku') THEN
    CREATE ROLE opoku LOGIN PASSWORD 'cEQ8ZlXSrS0DOGPuIHnEqq1L';
  END IF;
END
$$;

ALTER ROLE opoku PASSWORD 'cEQ8ZlXSrS0DOGPuIHnEqq1L';

CREATE DATABASE medusa_spike  OWNER opoku;
CREATE DATABASE laravel_spike OWNER opoku;
