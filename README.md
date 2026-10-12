New command: photos:prune-temp deletes party photo uploads in tmp/party-photos that are older than 24 hours. They pile up when a form is abandoned. The age is set with --hours, and --hours=0 is rejected.
Schedule: it runs daily at 03:27 Dhaka, registered in routes/console.php. schedule:list shows it alongside schedules:generate and schedules:mark-missed.
Tests: a new case in PartyPhotoTest checks that an old temp file is deleted while a fresh temp file and a stored photo stay. DateBoundaryTest now also asserts the prune entry's cron expression and Dhaka timezone.
Checks: the PartyPhotoTest and DateBoundaryTest tests pass (17), and PHPStan reports 0 errors. I ran Pint but did not run the full test suite.
Roadmap: the item is ticked, with a handoff note.
The real cron can't be tested here. Production still needs the line * * * * * php /path/artisan schedule:run. Run php artisan schedule:list on the server to confirm all three jobs appear.

Next roadmap item is the backup and restore test.