/* =====================================================================
   MyDentist – Migration 002 for SQL Server (optional manual run in SSMS)

   The website runs this automatically the first time it is opened after
   the update, so you normally do NOT need this file. It is provided so the
   change is documented and can be reviewed or run by hand.

   Changes (add-only, no data is deleted):
     users.avatar         profile picture file name
     appointments.is_demo marks demo/sample appointments
     appointments         Guest Mode bookings detached from email-linked accounts
     dentists             only Dr. Warren stays active; others are kept,
                          inactive, so old appointments keep their history
   ===================================================================== */
USE mydentist;
GO
IF COL_LENGTH('dbo.users', 'avatar')  IS NULL ALTER TABLE dbo.users ADD avatar NVARCHAR(100) NULL;
IF COL_LENGTH('dbo.appointments', 'is_demo') IS NULL
    ALTER TABLE dbo.appointments ADD is_demo BIT NOT NULL CONSTRAINT DF_appt_demo DEFAULT 0;
GO
-- Guest Mode bookings belong to their secret link, not to an account with the same email
UPDATE dbo.appointments SET patient_id = NULL WHERE source = N'guest' AND patient_id IS NOT NULL;
GO
DECLARE @warren INT = (SELECT TOP 1 id FROM dbo.dentists WHERE name LIKE N'%Warren%' ORDER BY is_active DESC, id);
IF @warren IS NOT NULL
BEGIN
    UPDATE dbo.dentists SET is_active = 0 WHERE id <> @warren;
    UPDATE dbo.dentists SET is_active = 1 WHERE id = @warren;
END
GO
IF EXISTS (SELECT 1 FROM dbo.settings WHERE skey = N'schema_version')
    UPDATE dbo.settings SET svalue = N'2' WHERE skey = N'schema_version';
ELSE
    INSERT INTO dbo.settings (skey, svalue) VALUES (N'schema_version', N'2');
GO
PRINT 'Migration 002 applied.';
