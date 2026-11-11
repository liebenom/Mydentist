/* =====================================================================
   MyDentist – Migration 004 for SQL Server (optional manual run in SSMS)

   The website runs this automatically the first time it is opened after
   the update, so you normally do NOT need this file.

   Change (add-only, no data is deleted):
     appointments.reminder_12_sent  12-hour reminder email sent
     appointments.reminder_6_sent   6-hour reminder email sent
   ===================================================================== */
USE mydentist;
GO
IF COL_LENGTH('dbo.appointments', 'reminder_12_sent') IS NULL
    ALTER TABLE dbo.appointments ADD reminder_12_sent BIT NOT NULL CONSTRAINT DF_appt_r12 DEFAULT 0;
IF COL_LENGTH('dbo.appointments', 'reminder_6_sent') IS NULL
    ALTER TABLE dbo.appointments ADD reminder_6_sent BIT NOT NULL CONSTRAINT DF_appt_r6 DEFAULT 0;
GO
IF EXISTS (SELECT 1 FROM dbo.settings WHERE skey = N'schema_version')
    UPDATE dbo.settings SET svalue = N'4' WHERE skey = N'schema_version';
ELSE
    INSERT INTO dbo.settings (skey, svalue) VALUES (N'schema_version', N'4');
GO
PRINT 'Migration 004 applied.';
