/* =====================================================================
   MyDentist – Migration 003 for SQL Server (optional manual run in SSMS)

   The website runs this automatically the first time it is opened after
   the update, so you normally do NOT need this file.

   Change (add-only, no data is deleted):
     appointments.extra_services  more services booked in the same visit,
                                  stored as ids like ,3,5,  (service_id stays
                                  the first service)
   ===================================================================== */
USE mydentist;
GO
IF COL_LENGTH('dbo.appointments', 'extra_services') IS NULL
    ALTER TABLE dbo.appointments ADD extra_services NVARCHAR(100) NULL;
GO
IF EXISTS (SELECT 1 FROM dbo.settings WHERE skey = N'schema_version')
    UPDATE dbo.settings SET svalue = N'3' WHERE skey = N'schema_version';
ELSE
    INSERT INTO dbo.settings (skey, svalue) VALUES (N'schema_version', N'3');
GO
PRINT 'Migration 003 applied.';
