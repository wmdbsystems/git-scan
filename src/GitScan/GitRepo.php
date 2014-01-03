<?php
namespace GitScan;

use GitScan\Util\Process as ProcessUtil;
use Symfony\Component\Filesystem\Filesystem;

class GitRepo {

  /**
   * @var string status code (cached)
   */
  private $statusCode;

  /**
   * @var Filesystem
   */
  private $fs;

  /**
   * @var string the bath in which "git" commands are executed
   */
  private $path;

  /**
   * @var string command-line output from "git status --porcelain" (cached)
   */
  private $porcelain;

  /**
   * @var string command-line output from "git status" (cached)
   */
  private $status;

  function __construct($path) {
    $this->fs = new Filesystem();
    $this->path = $path;
    $this->flush();
  }

  /* --------------- Main interfaces --------------- */

  /**
   * Get short status code
   *
   * @param bool $fresh
   * @return string eg " B  ", "BM S", or "    "
   */
  public function getStatusCode($fresh = FALSE) {
    if ($this->statusCode === NULL || $fresh) {
      $this->statusCode = '';

      if (basename($this->getLocalBranch()) != basename($this->getUpstreamBranch())) {
        $this->statusCode .= 'B';
      }
      else {
        $this->statusCode .= ' ';
      }

      if ($this->hasUncommittedChanges($fresh)) {
        $this->statusCode .= 'M';
      }
      else {
        $this->statusCode .= ' ';
      }

      if ($this->hasUntrackedFiles($fresh)) {
        $this->statusCode .= 'N';
      }
      else {
        $this->statusCode .= ' ';
      }

      if (!$this->isLocalFastForwardable($fresh)) {
        $this->statusCode .= 'P';
      }
      else {
        $this->statusCode .= ' ';
      }

      if ($this->hasStash()) {
        $this->statusCode .= 'S';
      }
      else {
        $this->statusCode .= ' ';
      }
    }

    return $this->statusCode;
  }

  /**
   * Determine the name of the local branch
   *
   * @return string|NULL the name of the local branch (eg "master"); NULL if detached
   * @throws \RuntimeException
   */
  public function getLocalBranch() {
    $process = $this->command("git symbolic-ref -q HEAD");
    $process->run();
    $symbolicRef = trim($process->getOutput());
    if (empty($symbolicRef)) {
      return NULL;
    }
    if (preg_match(":^refs/heads/(.*)$:", $symbolicRef, $matches)) {
      return $matches[1];
    }
    else {
      throw new \RuntimeException("Unrecognized symbolic ref [$symbolicRef]");
    }
  }

  /**
   * Determine the upstream/remote/tracking branch that corresponds to the currently
   * checked-out code.
   *
   * @return string|NULL name of the upstream branch ("some-remote/some-branch") or NULL if none
   * @throws \RuntimeException
   */
  public function getUpstreamBranch() {
    $process = $this->command("git rev-parse --abbrev-ref @{upstream}");
    $process->run();
    $symbolicRef = trim($process->getOutput());
    if (!$process->isSuccessful() && "@{upstream}" == $symbolicRef) {
      return NULL;
    }
    if (preg_match(":[a-zA-Z0-9\_\.\/]+:", $symbolicRef)) {
      return $symbolicRef;
    }
    else {
      throw new \RuntimeException("Failed to determine tracking branch");
    }
  }

  /**
   * Determine if there is any data in the stash
   *
   * @return bool
   */
  public function hasStash() {
    $process = ProcessUtil::runOk($this->command("git stash list"));
    return $process->getOutput() ? TRUE : FALSE;
  }

  /**
   * Determine if the local working-copy has uncommitted changes
   * (modified files or new+nonignored files).
   *
   * @return bool
   */
  public function hasUncommittedChanges($fresh = FALSE) {
    $porcelain = trim($this->getPorcelain($fresh));
    if (empty($porcelain)) {
      return FALSE;
    }
    $lines = explode("\n", $porcelain);
    $untracked = preg_grep('/^\?\?/', $lines);
    return count($lines) > count($untracked);
  }

  /**
   * Determine if the local working-copy has uncommitted changes
   * (modified files or new+nonignored files).
   *
   * @return bool
   */
  public function hasUntrackedFiles($fresh = FALSE) {
    $porcelain = trim($this->getPorcelain($fresh));
    if (empty($porcelain)) {
      return FALSE;
    }
    $lines = explode("\n", $porcelain);
    $untracked = preg_grep('/^\?\?/', $lines);
    return count($untracked) > 0;
  }

  public function isGitScan($fresh = FALSE) {
    return preg_match('/^ +$/', $this->getStatusCode($fresh));
  }

  /**
   * Determine if the local branch can be fast-forwarded to match the
   * remote branch.
   *
   * @return bool
   */
  public function isLocalFastForwardable($fresh = FALSE) {
    $lines = explode("\n", $this->getStatus($fresh));
    $lines = preg_grep('/^#/', $lines);
    $unknowns = array();
    foreach ($lines as $line) {
      $line = trim($line);

      if (preg_match('/^# Your branch is ahead of /', $line)) {
        return FALSE;
      }
      elseif (preg_match('/^# Your branch and .* diverged/', $line)) {
        return FALSE;
      }
      elseif (preg_match('/^# Your branch is behind.*can be fast-forwarded/', $line)) {
        return TRUE;
      }
      /*
      elseif ($line == '#') {
        continue; // ignore
      }
      elseif (preg_match('/^# (On branch|Not currently on any branch)/', $line)) {
        continue; // ignore
      }
      elseif (preg_match('/^# Untracked files/', $line)) {
        continue; // ignore
      }
      elseif (preg_match('/^#(\t|   )/', $line)) {
        continue; // ignore
      }
      else {
        $unknowns[] = $line;
      }
      */
    }
    // If there's no explicit mention of merge-ability, then it should be clean.
    // However, it's possible that the status message language has changed and we
    // don't know about it yet.

    if (count($unknowns) > 0) {
      throw new \RuntimeException("Failed to parse status of [" . $this->getPath() . "]:" . implode("\n", $unknowns));
    }
    return TRUE;
  }

  /**
   * @param $rule
   * @return bool
   * @throws \RuntimeException
   */
  public function matchesStatus($rule) {
    if ($rule == 'all') {
      return TRUE;
    }
    elseif ($rule == 'novel') {
      return !$this->isGitScan();
    }
    elseif ($rule == 'boring') {
      return $this->isGitScan();
    }
    else {
      throw new \RuntimeException("Unrecognized status filter");
    }
  }

  public function flush() {
    $this->porcelain = NULL;
    $this->status = NULL;
    $this->statusCode = NULL;
  }

  /* --------------- Helpers to facilitate testing --------------- */

  public function getPorcelain($fresh = FALSE) {
    if ($fresh || $this->porcelain === NULL) {
      $process = ProcessUtil::runOk($this->command("git status --porcelain"));
      $this->porcelain = $process->getOutput();
    }
    return $this->porcelain;
  }

  public function getStatus($fresh = FALSE) {
    if ($fresh || $this->status === NULL) {
      $process = ProcessUtil::runOk($this->command("git status"));
      $this->status = $process->getOutput();
    }
    return $this->status;
  }

  /**
   * @return bool TRUE if new one created; FALSE if already initialized
   */
  public function init() {
    if (!$this->fs->exists($this->path)) {
      $this->fs->mkdir($this->path);
    }
    if (!$this->fs->exists($this->path . DIRECTORY_SEPARATOR . '.git')) {
      ProcessUtil::runOk($this->command("git init"));
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Prepare a command to run in the repo's directory
   *
   * @param $command
   * @return \Symfony\Component\Process\Process
   */
  public function command($command) {
    $process = new \Symfony\Component\Process\Process($command);
    $process->setWorkingDirectory($this->getPath());
    return $process;
  }

  /**
   * @param string $relPath
   * @return string file content
   */
  public function readFile($relPath) {
    return file_get_contents($this->path . DIRECTORY_SEPARATOR . $relPath);
  }

  /**
   * @param string $relPath
   * @param string $content
   */
  public function writeFile($relPath, $content) {
    if (dirname($relPath)) {
      $this->fs->mkdir(dirname($relPath));
    }
    $this->fs->dumpFile($this->path . DIRECTORY_SEPARATOR . $relPath, $content);
  }

  /**
   * @param string $relPath
   * @param string $content
   * @param string|null $commitMessage
   */
  public function commitFile($relPath, $content, $commitMessage = NULL) {
    if ($commitMessage === NULL) {
      $commitMessage = "Update $relPath";
    }
    $this->writeFile($relPath, $content);
    ProcessUtil::runOk($this->command("git add " . escapeshellarg($relPath)));
    ProcessUtil::runOk($this->command("git commit " . escapeshellarg($relPath) . ' -m ' . escapeshellarg($commitMessage)));
  }

  /* --------------- Boiler plate --------------- */

  /**
   * @param string $path
   */
  public function setPath($path) {
    $this->path = $path;
  }

  /**
   * @return string
   */
  public function getPath() {
    return $this->path;
  }

}