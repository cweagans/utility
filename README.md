# Utility code

This is a collection of random crap that I have set up as scheduled tasks. I've
tried to generalize it enough that it'll be useful for others too, but I make
no promises.

## Prerequisites

* A recent version of PHP.
* Composer

## Installation

Run `composer install` and set up cron to run whatever you want.

## University of the People task importer

I'm a student at [UoPeople](http://uopeople.edu), and I use [Todoist](https://todoist.com)
to track my tasks. I get weekly assignments from UoPeople, and I normally just
manually enter them into my todo list. `uopeopleTasks.php` automates that by
logging into Moodle, scraping the data I want (this week's tasks), and POSTing
it to the IFTTT Maker channel, which dumps the task into my School list on Todoist.

This is all driven by the `uopeople.config.yml` config file, which is fairly
self-explanatory. To run, `php uopeopleTasks.php`.
