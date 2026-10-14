@echo off
rem Registers the automatic payment reminders with the Windows Task Scheduler (runs every hour while this PC is on).
rem Remove it later with:  schtasks /Delete /TN "Setlo reminders" /F
cd /d "%~dp0.."
schtasks /Create /SC HOURLY /TN "Setlo reminders" /TR "\"C:\xampp\php\php.exe\" \"%CD%\tools\send-reminders.php\"" /F
if errorlevel 1 (
  echo Could not create the task. Try running this file as Administrator.
) else (
  echo Done. Check it with:  schtasks /Query /TN "Setlo reminders"
)
