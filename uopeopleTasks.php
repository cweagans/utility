<?php

// Set up the autoloader.
require_once __DIR__ . "/vendor/autoload.php";

// Load some helper functions.
require_once __DIR__ . "/util.php";

use Goutte\Client;
use Symfony\Component\Yaml\Yaml;
use League\CLImate\CLImate;

// Create the cli i/o object.
$cli = new CLImate();

// Bail out early if we don't have a config file. We're gonna need that.
$config_file = __DIR__ . "/uopeople.config.yml";
if (!file_exists($config_file) || !is_readable($config_file)) {
    $cli->error("Config file is missing or unreadable!");
    exit(1);
}

// Open the config file.
$config = Yaml::parse(file_get_contents($config_file));

// Warn about skip_sending if enabled.
if (isset($config['ifttt']['skip_sending']) && $config['ifttt']['skip_sending'] === TRUE) {
    $cli->info("WARNING: skip_sending is enabled, which means that tasks WILL NOT be sent to Todoist.");
}

// Create the Goutte client.
$client = new Client();

// Log in to Moodle.
$cli->info("- Logging in to Moodle.");
$cli->comment("  - Loading login page.");
$crawler = $client->request('GET', $config['uopeople']['moodle_login_url']);
$cli->comment("  - Login page loaded.");
$login_form = $crawler->selectButton("Log in")->form();
$cli->comment("  - Submitting login form.");
$crawler = $client->submit($login_form, [
    "username" => $config['uopeople']['username'],
    "password" => $config['uopeople']['password'],
]);
$cli->comment("  - Login form submitted.");

// Navigate to the list of classes I'm taking this term. This is the link in the
// left sidebar immediately after logging in to Moodle.
$cli->info("- Getting a list of courses.");
$cli->comment("  - Loading 'My Courses' page.");
$link = $crawler->selectLink("My courses")->link();
$crawler = $client->click($link);
$cli->comment("  - 'My Courses' page loaded.");

// Dump individual courses into an array so we can loop through them later.
$course_urls = [];

// Output a list.
$crawler->filter('.coursebox h2 a')->each(function ($node) use (&$course_urls, $cli) {
    // Filter out the noise.
    $cli->comment("  - Found course: " . $node->text());

    if (!str_contains(strtolower($node->text()), ["peer assessment", "student writing center"])) {
        $cli->comment("  - Adding " . $node->text() . " to the list of courses to retreive assignments for.");
        $course_urls[] = [
            "name" => $node->attr('title'),
            "url" => $node->attr('href'),
        ];
    }
    else {
        $cli->comment("  - " . $node->text() . " skipped (not a real course -- library information and the like).");
    }
});

$cli->info(" - Getting course assignments.");
$assignments = [];
foreach ($course_urls as $course) {
    // The thinking here is that the course name is always going to be something like this:
    // CS 1102 Programming 1 - T3 2015-2016
    // or
    // CS1102 Programming 1 - T3 2015-2016
    // If we explode on spaces and the second part is numeric, then we know we want part1.part2
    // If part2 is not numeric, then we just want part 1.
    $course_parts = explode(" ", $course['name']);
    $shortened_name = $course_parts[0];
    if (is_numeric($course_parts[1])) {
        $shortened_name = $course_parts[0] . $course_parts[1];
    }

    $cli->comment("  - Working on " . $shortened_name);
    $cli->whisper("    - Loading course page.");
    $crawler = $client->request("GET", $course['url']);
    $cli->whisper("    - Course page loaded.");

    // Find the bottom Week section on the page, find the LG link where they're
    // using a book icon, and get the link from there.
    $lg_link = $crawler->filter('.weeks .section')->last()->filter('li.modtype_book a')->link();
    $cli->whisper("    - Found latest learning guide link.");
    $cli->whisper("    - Loading learning guide.");

    $crawler = $client->click($lg_link);
    $cli->whisper("    - Learning guide page loaded.");

    $checklist_link = $crawler->filter('.block_book_toc a')->selectLink("Checklist")->link();
    $cli->whisper("    - Found checklist link.");
    $cli->whisper("    - Loading checklist page.");

    $crawler = $client->click($checklist_link);
    $cli->whisper("    - Loaded checklist page.");

    $cli->whisper("    - Finding assignments.");
    $assignment_count = count($assignments);

    // @todo this is a really stupid filter to use for the checklist items, but I guess it works.
    $crawler->filter('#region-main p')->each(function ($node) use (&$assignments, $shortened_name) {
        if (!empty($node->text())) {
            $assignments[] = $shortened_name . ": " . $node->text();
        }
    });

    $assignments_found = (count($assignments) - $assignment_count);
    $cli->whisper("    - Found " . $assignments_found . " assignments for " . $shortened_name . ".");
    $cli->whisper("    - Done with " . $shortened_name . ".");
}

$cli->info(" - Sending tasks to Todoist via IFTTT.");

// This is a really long URL.
$ifttt_endpoint = "https://maker.ifttt.com/trigger/";
$ifttt_endpoint .= $config['ifttt']['maker_channel_event'];
$ifttt_endpoint .= "/with/key/";
$ifttt_endpoint .= $config['ifttt']['maker_channel_key'];
$cli->info("Endpoint: " . $ifttt_endpoint);

// Create a guzzle client to use for sending assignments to Todoist.
$guzzleClient = new GuzzleHttp\Client();

foreach ($assignments as $assignment) {
    if (isset($config['ifttt']['skip_sending']) && $config['ifttt']['skip_sending'] === TRUE) {
        $cli->comment("  - Would have sent: '" . $assignment ."' (sending skipped).");
    }
    else {
        // Send the request.
        // $response = $guzzleClient->request("POST", $ifttt_endpoint, [
        //     'value1' => $assignment,
        // ]);
        $response = $guzzleClient->request("POST", $ifttt_endpoint, [
            'json' => [
                'value1' => $assignment,
            ],
        ]);

        if ($response->getStatusCode() == "200") {
            $cli->comment("  - sent: '" . $assignment ."'.");
        }
        else {
            $cli->error("  - Error sending: '" . $assignment ."'!");
        }
    }

}

$cli->info("Done.");
