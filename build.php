#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ask the user a question and get the answer.
 * If the user provides no answer, return the default value.
 */
function ask(string $question, string $default = ''): string
{
    $answer = readline('-> ' . $question . ($default ? " ({$default})" : null) . ': ');

    if (! $answer) {
        return $default;
    }

    return $answer;
}

/**
 * Ask yes/no confirmation question to the user.
 * If the user provides no answer, return the default value.
 */
function confirm(string $question, bool $default = false): bool
{
    $answer = ask($question . ' (' . ($default ? 'Y/n' : 'y/N') . ')');

    if (! $answer) {
        return $default;
    }

    return strtolower($answer) === 'y';
}

/**
 * Prints a line of text to the console, followed by a newline.
 */
function writeln(string $line): void
{
    echo $line . PHP_EOL;
}

/**
 * Executes a shell command and returns the trimmed output as a string.
 */
function run(string $command): string
{
    return trim((string) shell_exec($command));
}

/**
 * Converts a string into a slug by replacing non-alphanumeric characters with hyphens.
 */
function slugify(string $subject): string
{
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $subject), '-'));
}

/**
 * Converts a string (potentially with hyphens or underscores) into PascalCase (e.g., my-package becomes MyPackage).
 */
function titleCase(string $subject): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $subject)));
}

/**
 * Reads the content of a file, performs multiple string replacements using the provided associative array,
 * and writes the modified content back to the file.
 */
function replaceInFile(string $file, array $replacements): void
{
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        str_replace(
            array_keys($replacements),
            array_values($replacements),
            $contents
        )
    );
}

/**
 * Removes content within the file.
 */
function removeReadmeParagraphs(string $file): void
{
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        preg_replace('/<!--delete-->.*<!--\/delete-->/s', '', $contents) ?: $contents
    );
}

/**
 * Determines the appropriate directory separator for the given path string.
 * Replaces all '/' characters with the system's DIRECTORY_SEPARATOR.
 */
function determineSeparator(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

/**
 * Searches for files containing specific placeholder strings and returns an array of their paths.
 * Excludes the 'vendor' directory and the current script file from the search.
 */
function replacePlaceholders(): array
{
    return explode(PHP_EOL, run('grep -E -r -l -i ":author|:vendor|:package|VendorName|skeleton|vendor_name|vendor_slug" --exclude-dir=vendor ./* ./.github/* | grep -v ' . basename(__FILE__)));
}

/**
 * Fetches data from a specified GitHub API endpoint and returns the decoded JSON response as an object.
 * If the request fails or the response is not successful, returns null.
 */
function getGitHubApiEndpoint(string $endpoint): ?stdClass
{
    try {
        $curl = curl_init("https://api.github.com/{$endpoint}");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: laravel-package-template/1.0',
            ],
        ]);

        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($statusCode === 200) {
            return json_decode($response);
        }
    } catch (Exception $e) {
    }

    return null;
}

/**
 * Tries to guess the GitHub username from the email in Git commits
 */
function searchCommitsForGitHubUsername(): string
{
    $authorUserName = strtolower(trim(shell_exec('git config user.name')));

    $committersRaw = shell_exec("git log --author='@users.noreply.github.com' --pretty='%an:%ae' --reverse");
    $committersLines = explode("\n", $committersRaw ?? '');
    $committers = array_filter(array_map(function ($line) use ($authorUserName) {
        $line = trim($line);
        [$name, $email] = explode(':', $line) + [null, null];

        return [
            'name' => $name,
            'email' => $email,
            'isMatch' => strtolower($name) === $authorUserName && ! str_contains($name, '[bot]'),
        ];
    }, $committersLines), fn ($item) => $item['isMatch']);

    if (empty($committers)) {
        return '';
    }

    $firstCommitter = reset($committers);

    return explode('@', $firstCommitter['email'])[0] ?? '';
}

/**
 * Tries to guess the GitHub username by checking the GitHub CLI authentication status
 */
function guessGitHubUsernameUsingCli()
{
    try {
        if (preg_match('/logged in to github\.com as ([a-zA-Z-_]+).+/', shell_exec('gh auth status -h github.com 2>&1'), $matches)) {
            return $matches[1];
        }
    } catch (Exception $e) {
    }

    return '';
}

/**
 * Attempts to find the GitHub username using searchCommitsForGitHubUsername(), then guessGitHubUsernameUsingCli(),
 * and finally by parsing the remote Git URL.
 */
function guessGitHubUsername(): string
{
    $username = searchCommitsForGitHubUsername();

    if (! empty($username)) {
        return $username;
    }

    $username = guessGitHubUsernameUsingCli();

    if (! empty($username)) {
        return $username;
    }

    // fall back to using the username from the git remote
    $remoteUrl = shell_exec('git config remote.origin.url') ?? '';
    $remoteUrlParts = explode('/', str_replace(':', '/', trim($remoteUrl)));

    return $remoteUrlParts[1] ?? '';
}

/**
 * Attempts to fetch the GitHub vendor (organization) name and login using the GitHub API.
 * If the API call fails, it falls back to using the provided author name and username.
 */
function guessGitHubVendorInfo($authorName, $username): array
{
    $remoteUrl = shell_exec('git config remote.origin.url') ?? '';
    $remoteUrlParts = explode('/', str_replace(':', '/', trim($remoteUrl)));

    if (! isset($remoteUrlParts[1])) {
        return [$authorName, $username];
    }

    $response = getGitHubApiEndpoint("orgs/{$remoteUrlParts[1]}");

    if ($response === null) {
        return [$authorName, $username];
    }

    return [$response->name ?? $authorName, $response->login ?? $username];
}

// Start of the script
$gitName = run('git config user.name');

// Ask and get the author name and username
$authorUsername = ask('Author username', $gitName);
$authorName = ask('Author name', guessGitHubUsername());

// Get vendor related info including name, username and namespace etc.
$guessGitHubVendorInfo = guessGitHubVendorInfo($authorName, $authorUsername);
$vendorName = ask('Vendor name', $guessGitHubVendorInfo[0]);
$vendorUsername = ask('Vendor username', $guessGitHubVendorInfo[1] ?? slugify($vendorName));
$vendorSlug = slugify($vendorUsername);
$vendorNamespace = str_replace(' ', '', ucwords(str_replace('-', ' ', $vendorName)));
$vendorNamespace = ask('Vendor namespace', $vendorNamespace);

// Get the current dir and folder name
$currentDirectory = getcwd();
$folderName = basename($currentDirectory);

// Ask and get the package name and slug from the user
$packageName = ask('Package name', $folderName);
$packageSlug = slugify($packageName);

// Ask and get the class name, variable name and description
$className = titleCase($packageName);
$className = ask('Class name', $className);
$variableName = lcfirst($className);
$description = ask('Package description', "This is my package {$packageSlug}");

// Display summary before proceeding
writeln('----------------------');
writeln("Author     : {$authorUsername} ({$authorName})");
writeln("Vendor     : {$vendorSlug} ({$vendorName})");
writeln("Package    : {$packageSlug} <{$description}>");
writeln("Namespace  : {$vendorNamespace}\\{$className}");
writeln("Class name : {$className}");
writeln('----------------------');

writeln('This script will update all relevant files in the project.');

if (! confirm('Modify files?', true)) {
    exit(1);
}

$files = replacePlaceholders();

// File replacements and renames
foreach ($files as $file) {
    replaceInFile($file, [
        ':author_name' => $authorName,
        ':author_username' => $authorUsername,
        ':vendor_name' => $vendorName,
        ':vendor_slug' => $vendorSlug,
        'VendorName' => $vendorNamespace,
        ':package_name' => $packageName,
        ':package_slug' => $packageSlug,
        'Skeleton' => $className,
        'skeleton' => $packageSlug,
        'variable' => $variableName,
        ':package_description' => $description,
    ]);

    match (true) {
        str_contains($file, determineSeparator('src/Skeleton.php')) => rename($file, determineSeparator('./src/' . $className . '.php')),
        str_contains($file, determineSeparator('src/SkeletonServiceProvider.php')) => rename($file, determineSeparator('./src/' . $className . 'ServiceProvider.php')),
        str_contains($file, determineSeparator('src/Facades/Skeleton.php')) => rename($file, determineSeparator('./src/Facades/' . $className . '.php')),
        str_contains($file, determineSeparator('config/skeleton.php')) => rename($file, determineSeparator('./config/' . $packageSlug . '.php')),
        str_contains($file, 'README.md') => removeReadmeParagraphs($file),
        default => [],
    };
}

// Ask to install composer dependencies
confirm('Execute `composer install` now?', true) && run('composer install');

// Remove the build.php script
confirm('Let this script delete itself?', true) && unlink(__FILE__);
