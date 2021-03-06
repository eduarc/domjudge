<?php declare(strict_types=1);

namespace DOMJudgeBundle\Twig;

use Doctrine\ORM\EntityManagerInterface;
use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Entity\Judging;
use DOMJudgeBundle\Entity\Submission;
use DOMJudgeBundle\Entity\SubmissionFileWithSourceCode;
use DOMJudgeBundle\Entity\Testcase;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Service\EventLogService;
use DOMJudgeBundle\Service\SubmissionService;
use DOMJudgeBundle\Utils\Utils;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\TwigFunction;

class TwigExtension extends \Twig\Extension\AbstractExtension implements \Twig\Extension\GlobalsInterface
{
    /**
     * @var DOMJudgeService
     */
    protected $domjudge;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var SubmissionService
     */
    protected $submissionService;

    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * @var KernelInterface
     */
    protected $kernel;

    public function __construct(
        DOMJudgeService $domjudge,
        EntityManagerInterface $entityManager,
        SubmissionService $submissionService,
        EventLogService $eventLogService,
        KernelInterface $kernel
    ) {
        $this->domjudge          = $domjudge;
        $this->entityManager     = $entityManager;
        $this->submissionService = $submissionService;
        $this->eventLogService   = $eventLogService;
        $this->kernel            = $kernel;
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('button', [$this, 'button'], ['is_safe' => ['html']]),
            new TwigFunction('calculatePenaltyTime', [$this, 'calculatePenaltyTime']),
            new TwigFunction('showExternalId', [$this, 'showExternalId']),
        ];
    }

    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('timediff', [$this, 'timediff']),
            new \Twig_SimpleFilter('printtime', [$this, 'printtime']),
            new \Twig_SimpleFilter('printtimeHover', [$this, 'printtimeHover'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('printResult', [$this, 'printResult'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('printHost', [$this, 'printHost'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('printYesNo', [$this, 'printYesNo']),
            new \Twig_SimpleFilter('printSize', [Utils::class, 'printSize'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('testcaseReults', [$this, 'testcaseReults'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('displayTestcaseResults', [$this, 'displayTestcaseResults'],
                                   ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('externalCcsUrl', [$this, 'externalCcsUrl']),
            new \Twig_SimpleFilter('lineCount', [$this, 'lineCount']),
            new \Twig_SimpleFilter('base64', 'base64_encode'),
            new \Twig_SimpleFilter('base64_decode', 'base64_decode'),
            new \Twig_SimpleFilter('parseRunDiff', [$this, 'parseRunDiff'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('runDiff', [$this, 'runDiff'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('codeEditor', [$this, 'codeEditor'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('showDiff', [$this, 'showDiff'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('printContestStart', [$this, 'printContestStart']),
            new \Twig_SimpleFilter('assetExists', [$this, 'assetExists']),
            new \Twig_SimpleFilter('printTimeRelative', [$this, 'printTimeRelative']),
            new \Twig_SimpleFilter('scoreTime', [$this, 'scoreTime']),
            new \Twig_SimpleFilter('statusClass', [$this, 'statusClass']),
            new \Twig_SimpleFilter('statusIcon', [$this, 'statusIcon']),
            new \Twig_SimpleFilter('descriptionExpand', [$this, 'descriptionExpand'], ['is_safe' => ['html']]),
            new \Twig_SimpleFilter('wrapUnquoted', [$this, 'wrapUnquoted']),
        ];
    }

    public function getGlobals()
    {
        $refresh_cookie = $this->domjudge->getCookie("domjudge_refresh");
        $refresh_flag   = ($refresh_cookie == null || (bool)$refresh_cookie);

        // TODO: use domserver-static.php defines here
        $dir = realpath(sprintf('%s/../../etc', $this->kernel->getRootDir()));
        require_once $dir . '/domserver-config.php';

        $user = $this->domjudge->getUser();
        $team = $user ? $user->getTeam() : null;

        // These variables mostly exist for the header template
        return [
            'current_contest' => $this->domjudge->getCurrentContest(),
            'current_contests' => $this->domjudge->getCurrentContests(),
            'current_public_contest' => $this->domjudge->getCurrentContest(-1),
            'current_public_contests' => $this->domjudge->getCurrentContests(-1),
            'have_printing' => $this->domjudge->dbconfig_get('enable_printing', 0),
            'refresh_flag' => $refresh_flag,
            'icat_url' => defined('ICAT_URL') ? ICAT_URL : null,
            'ext_ccs_url' => defined('EXT_CCS_URL') ? EXT_CCS_URL : null,
            'current_team_contest' => $team ? $this->domjudge->getCurrentContest($user->getTeamid()) : null,
            'current_team_contests' => $team ? $this->domjudge->getCurrentContests($user->getTeamid()) : null,
            'submission_languages' => $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:Language', 'l')
                ->select('l')
                ->andWhere('l.allowSubmit = 1')
                ->getQuery()
                ->getResult(),
        ];
    }

    public function timediff($start, $end = null)
    {
        if (is_null($end)) {
            $end = Utils::now();
        }
        $ret  = '';
        $diff = floor($end - $start);

        if ($diff >= 24 * 60 * 60) {
            $d    = floor($diff / (24 * 60 * 60));
            $ret  .= $d . "d ";
            $diff -= $d * 24 * 60 * 60;
        }
        if ($diff >= 60 * 60 || isset($d)) {
            $h    = floor($diff / (60 * 60));
            $ret  .= $h . ":";
            $diff -= $h * 60 * 60;
        }
        $m    = floor($diff / 60);
        $ret  .= sprintf('%02d:', $m);
        $diff -= $m * 60;
        $ret  .= sprintf('%02d', $diff);

        return $ret;
    }

    /**
     * Print a time formatted as specified. The format is according to strftime().
     * @param string|float $datetime
     * @param string|null  $format
     * @param Contest|null $contest If given, print time relative to that contest start.
     * @return string
     * @throws \Exception
     */
    public function printtime($datetime, string $format = null, Contest $contest = null): string
    {
        if ($datetime === null) {
            $datetime = Utils::now();
        }
        if ($contest !== null && $this->domjudge->dbconfig_get('show_relative_time', false)) {
            $relativeTime = $contest->getContestTime((float)$datetime);
            $sign         = ($relativeTime < 0 ? -1 : 1);
            $relativeTime *= $sign;
            // We're not showing seconds, while the last minute before
            // contest start should show as "-0:01", so if there's a
            // nonzero amount of seconds before the contest, we have to
            // add a minute.
            $s            = $relativeTime % 60;
            $relativeTime = ($relativeTime - $s) / 60;
            if ($sign < 0 && $s > 0) {
                $relativeTime++;
            }
            $m            = $relativeTime % 60;
            $relativeTime = ($relativeTime - $m) / 60;
            $h            = $relativeTime;
            if ($sign < 0) {
                return sprintf("-%d:%02d", $h, $m);
            } else {
                return sprintf("%d:%02d", $h, $m);
            }
        } else {
            if ($format === null) {
                $format = $this->domjudge->dbconfig_get('time_format', '%H:%M');
            }
            return Utils::printtime($datetime, $format);
        }
    }

    /**
     * Helper function to print a time in the default/configured format,
     * and a hover title attribute with the full datetime string.
     *
     * @param string|float $datetime
     * @param Contest|null $contest If given, print time relative to that contest start.
     * @return string
     * @throws \Exception
     */
    public function printtimeHover($datetime, Contest $contest = null): string
    {
        if ($datetime === null) {
            $datetime = Utils::now();
        }
        return '<span title="' .
            Utils::printtime($datetime, '%Y-%m-%d %H:%M (%Z)') . '">' .
            $this->printtime($datetime, null, $contest) .
            '</span>';
    }

    /**
     * print a yes/no field
     * @param bool $val
     * @return string
     */
    public static function printYesNo(bool $val): string
    {
        return $val ? 'Yes' : 'No';
    }

    /**
     * render a button
     * @param string      $url
     * @param string      $text
     * @param string      $type
     * @param string|null $icon
     * @param bool        $isAjaxModal
     * @return string
     */
    public function button(
        string $url,
        string $text,
        string $type = 'primary',
        string $icon = null,
        bool $isAjaxModal = false
    ) {
        if ($icon) {
            $icon = sprintf('<i class="fas fa-%s"></i>&nbsp;', $icon);
        }

        if ($isAjaxModal) {
            return sprintf('<a href="%s" class="btn btn-%s" title="%s" data-ajax-modal>%s%s</a>', $url, $type, $text,
                           $icon, $text);
        } else {
            return sprintf('<a href="%s" class="btn btn-%s" title="%s">%s%s</a>', $url, $type, $text, $icon, $text);
        }
    }

    /**
     * Map user/team/judgehost status to a cssclass
     * @param string $status
     * @return string
     */
    public static function statusClass(string $status): string
    {
        switch ($status) {
            case 'noconn':
                return 'text-muted';
            case 'crit':
                return 'text-danger';
            case 'warn':
                return 'text-warning';
            case 'ok':
                return 'text-success';
        }
        return '';
    }

    /**
     * Map user/team/judgehost status to an icon
     * @param string $status
     * @return string
     */
    public static function statusIcon(string $status): string
    {
        switch ($status) {
            case 'noconn':
                $icon = 'question';
                break;
            case 'crit':
                $icon = 'times';
                break;
            case 'warn':
                $icon = 'exclamation';
                break;
            case 'ok':
                $icon = 'check';
                break;
            default:
                return $status;
        }
        return sprintf('<i class="fas fa-%s-circle"></i>', $icon);
    }

    /**
     * Output the testcase results for the given submissions
     * @param Submission $submission
     * @return string
     */
    public function testcaseReults(Submission $submission)
    {
        // We use a direct SQL query here for performance reasons
        $judging   = $submission->getJudgings()->first();
        $judgingId = $judging ? $judging->getJudgingid() : null;
        $probId    = $submission->getProbid();
        $testcases = $this->entityManager->getConnection()->fetchAll(
            'SELECT r.runresult, t.rank, t.description
                  FROM testcase t
                  LEFT JOIN judging_run r ON (r.testcaseid = t.testcaseid
                                              AND r.judgingid = :judgingid)
                  WHERE t.probid = :probid ORDER BY rank', [':judgingid' => $judgingId, ':probid' => $probId]);

        $submissionDone = $judging ? !empty($judging->getEndtime()) : false;

        $results = '';
        foreach ($testcases as $key => $testcase) {
            $class = $submissionDone ? 'secondary' : 'primary';
            $text  = '?';

            if ($testcase['runresult'] !== null) {
                $text  = substr($testcase['runresult'], 0, 1);
                $class = 'danger';
                if ($testcase['runresult'] === Judging::RESULT_CORRECT) {
                    $text  = '✓';
                    $class = 'success';
                }
            }

            if (!empty($testcase['description'])) {
                $title = sprintf('Run %d: %s', $key + 1,
                                 Utils::specialchars($testcase['description']));
            } else {
                $title = sprintf('Run %d', $key + 1);
            }

            $results .= sprintf('<span class="badge badge-%s badge-testcase" title="%s">%s</span>', $class, $title,
                                $text);
        }

        return $results;
    }

    /**
     * Display testcase results
     *
     * TODO: this function shares a lot with the above one, unify them?
     *
     * @param Testcase[] $testcases
     * @param bool       $submissionDone
     * @return string
     */
    public function displayTestcaseResults(array $testcases, bool $submissionDone)
    {
        $results = '';
        foreach ($testcases as $testcase) {
            $class     = $submissionDone ? 'secondary' : 'primary';
            $text      = '?';
            $isCorrect = false;
            $run       = $testcase->getFirstJudgingRun();

            if ($run && $run->getRunresult() !== null) {
                $text  = substr($run->getRunresult(), 0, 1);
                $class = 'danger';
                if ($run->getRunresult() === Judging::RESULT_CORRECT) {
                    $isCorrect = true;
                    $text      = '✓';
                    $class     = 'success';
                }
            }

            $description = $testcase->getDescription(true);


            $extraTitle = '';
            if ($run && $run->getRunresult() !== null) {
                $extraTitle = sprintf(', runtime: %ss, result: %s', $run->getRuntime(), $run->getRunresult());
            }
            $icon    = sprintf('<span class="badge badge-%s badge-testcase">%s</span>', $class, $text);
            $results .= sprintf('<a title="#%d, desc: %s%s" href="#run-%d" %s>%s</a>', $testcase->getRank(),
                                $description, $extraTitle, $testcase->getRank(),
                                $isCorrect ? 'onclick="display_correctruns(true);"' : '', $icon);
        }

        return $results;
    }

    /**
     * Print the given result
     * @param string $result
     * @param bool   $valid
     * @param bool   $jury
     * @return string
     */
    public function printResult($result, bool $valid = true, bool $jury = false): string
    {
        switch ($result) {
            case 'too-late':
                $style = 'sol_queued';
                break;
            /** @noinspection PhpMissingBreakStatementInspection */
            case '':
                $result = 'judging';
            // no break
            case 'judging':
            case 'queued':
                if (!$jury) {
                    $result = 'pending';
                }
                $style = 'sol_queued';
                break;
            case 'correct':
                $style = 'sol_correct';
                break;
            default:
                $style = 'sol_incorrect';
        }

        return sprintf('<span class="sol %s">%s</span>', $valid ? $style : 'disabled', $result);
    }

    /**
     * Return the URL to an external CCS for the given submission if available
     * @param Submission $submission
     * @return string|null
     */
    public function externalCcsUrl(Submission $submission)
    {
        // TODO: use domserver-static.php defines here
        $dir = realpath(sprintf('%s/../../etc', $this->kernel->getRootDir()));
        require_once $dir . '/domserver-config.php';

        if (defined('EXT_CCS_URL') && $submission->getExternalid()) {
            return sprintf('%s%s', EXT_CCS_URL, $submission->getExternalid());
        }

        return null;
    }

    /**
     * Formats a given hostname. If $full = true, then the full hostname will be printed,
     * else only the local part (for keeping tables readable)
     * @param string $hostname
     * @param bool   $full
     * @return string
     */
    public function printHost(string $hostname, bool $full = false): string
    {
        // Shorten the hostname to first label, but not if it's an IP address.
        if (!$full && !preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $hostname)) {
            $expl     = explode('.', $hostname);
            $hostname = array_shift($expl);
        }

        return sprintf('<span class="hostname">%s</span>', Utils::specialchars($hostname));
    }

    /**
     * Get the number of lines in a given string
     * @param string $input
     * @return int
     */
    public function lineCount(string $input): int
    {
        return mb_substr_count($input, "\n");
    }

    /**
     * Parse the run diff for a given difftext
     * @param string $difftext
     * @return string
     */
    public function parseRunDiff(string $difftext): string
    {
        $line = strtok($difftext, "\n"); //first line
        if ($line === false || sscanf($line, "### DIFFERENCES FROM LINE %d ###\n", $firstdiff) != 1) {
            return Utils::specialchars($difftext);
        }
        $return = $line . "\n";

        // Add second line 'team ? reference'
        $line   = strtok("\n");
        $return .= $line . "\n";

        // We determine the line number width from the '_' characters and
        // the separator position from the character '?' on the second line.
        $linenowidth = mb_strrpos($line, '_') + 1;
        $midloc      = mb_strpos($line, '?') - ($linenowidth + 1);

        $line = strtok("\n");
        while (mb_strlen($line) != 0) {
            $linenostr = mb_substr($line, 0, $linenowidth);
            $diffline  = mb_substr($line, $linenowidth + 1);
            $mid       = mb_substr($diffline, $midloc - 1, 3);
            switch ($mid) {
                case ' = ':
                    $formdiffline = "<span class='correct'>" . Utils::specialchars($diffline) . "</span>";
                    break;
                case ' ! ':
                    $formdiffline = "<span class='differ'>" . Utils::specialchars($diffline) . "</span>";
                    break;
                case ' $ ':
                    $formdiffline = "<span class='endline'>" . Utils::specialchars($diffline) . "</span>";
                    break;
                case ' > ':
                case ' < ':
                    $formdiffline = "<span class='extra'>" . Utils::specialchars($diffline) . "</span>";
                    break;
                default:
                    $formdiffline = Utils::specialchars($diffline);
            }
            $return = $return . $linenostr . " " . $formdiffline . "\n";
            $line   = strtok("\n");
        }
        return $return;
    }

    /**
     * Output a run diff
     * @param array $runOutput
     * @return string
     * @throws \Exception
     */
    public function runDiff(array $runOutput)
    {
        // TODO: can be improved using diffposition.txt
        // FIXME: only show when diffposition.txt is set?
        // FIXME: cut off after XXX lines
        $lines_team = preg_split('/\n/', trim($runOutput['output_run']));
        $lines_ref  = preg_split('/\n/', trim($runOutput['output_reference']));

        $diffs    = array();
        $firstErr = sizeof($lines_team) + 1;
        $lastErr  = -1;
        $n        = min(sizeof($lines_team), sizeof($lines_ref));
        for ($i = 0; $i < $n; $i++) {
            $lcs = Utils::computeLcsDiff($lines_team[$i], $lines_ref[$i]);
            if ($lcs[0] === true) {
                $firstErr = min($firstErr, $i);
                $lastErr  = max($lastErr, $i);
            }
            $diffs[] = $lcs[1];
        }
        $contextLines = 5;
        $firstErr     -= $contextLines;
        $lastErr      += $contextLines;
        $firstErr     = max(0, $firstErr);
        $lastErr      = min(sizeof($diffs) - 1, $lastErr);
        $result       = "<br/>\n<table class=\"lcsdiff output_text\">\n";
        if ($firstErr > 0) {
            $result .= "<tr><td class=\"linenr\">[...]</td><td/></tr>\n";
        }
        for ($i = $firstErr; $i <= $lastErr; $i++) {
            $result .= "<tr><td class=\"linenr\">" . ($i + 1) . "</td><td>" . $diffs[$i] . "</td></tr>";
        }
        if ($lastErr < sizeof($diffs) - 1) {
            $result .= "<tr><td class=\"linenr\">[...]</td><td/></tr>\n";
        }
        $result .= "</table>\n";

        return $result;
    }

    /**
     * Output a (readonly) code editor for the given submission file
     * @param string      $code
     * @param string      $index
     * @param string|null $language        Ace language to use
     * @param bool        $editable        Whether to allow editing
     * @param string      $elementToUpdate HTML element to update when input changes
     * @param string|null $filename        If $language is null, filename to use to determine language
     * @return string
     */
    public function codeEditor(
        string $code,
        string $index,
        string $language = null,
        bool $editable = false,
        string $elementToUpdate = '',
        string $filename = null
    ) {
        $editor = <<<HTML
<div class="editor" id="__EDITOR__">%s</div>
<script>
var __EDITOR__ = ace.edit("__EDITOR__");
__EDITOR__.setTheme("ace/theme/eclipse");
__EDITOR__.setOptions({ maxLines: Infinity });
__EDITOR__.setReadOnly(%s);
%s
document.getElementById("__EDITOR__").editor = __EDITOR__;
%s
</script>
HTML;
        $rank   = $index;
        $id     = sprintf('editor%s', $rank);
        $code   = Utils::specialchars($code);
        if ($elementToUpdate) {
            $extraForEdit = <<<JS
__EDITOR__.getSession().on('change', function() {
    var textarea = document.getElementById("$elementToUpdate");
    textarea.value = __EDITOR__.getSession().getValue();
});
JS;

        } else {
            $extraForEdit = '';
        }

        if ($language !== null) {
            $mode = sprintf('__EDITOR__.getSession().setMode("ace/mode/%s");', $language);
        } elseif ($filename !== null) {
            $modeTemplate = <<<JS
var modelist = ace.require('ace/ext/modelist');
var filePath = "%s";
var mode = modelist.getModeForPath(filePath).mode;
__EDITOR__.getSession().setMode(mode);
JS;
            $mode         = sprintf($modeTemplate, Utils::specialchars($filename));
        } else {
            $mode = '';
        }

        return str_replace('__EDITOR__', $id,
                           sprintf($editor, $code, $editable ? 'false' : 'true', $mode, $extraForEdit));
    }


    /**
     * Parse the given source diff
     * @param $difftext
     * @return string
     */
    protected function parseSourceDiff($difftext)
    {
        $line   = strtok((string)$difftext, "\n"); // first line
        $return = '';
        while ($line !== false && strlen($line) != 0) {
            // Strip any additional DOS/MAC newline characters:
            $line = trim($line, "\r\n");
            switch (substr($line, 0, 1)) {
                case '-':
                    $formdiffline = "<span class='diff-del'>" . Utils::specialchars($line) . "</span>";
                    break;
                case '+':
                    $formdiffline = "<span class='diff-add'>" . Utils::specialchars($line) . "</span>";
                    break;
                default:
                    $formdiffline = Utils::specialchars($line);
            }
            $return .= $formdiffline . "\n";
            $line   = strtok("\n");
        }
        return $return;
    }

    /**
     * Show a diff between two files
     * @param SubmissionFileWithSourceCode $newFile
     * @param SubmissionFileWithSourceCode $oldFile
     * @return string
     */
    public function showDiff(SubmissionFileWithSourceCode $newFile, SubmissionFileWithSourceCode $oldFile)
    {
        $newsourcefile = $this->submissionService->getSourceFilename([
                                                                         'cid' => $newFile->getSubmission()->getCid(),
                                                                         'submitid' => $newFile->getSubmitid(),
                                                                         'teamid' => $newFile->getSubmission()->getTeamid(),
                                                                         'probid' => $newFile->getSubmission()->getProbid(),
                                                                         'langid' => $newFile->getSubmission()->getLangid(),
                                                                         'rank' => $newFile->getRank(),
                                                                         'filename' => $newFile->getFilename()
                                                                     ]);
        $oldsourcefile = $this->submissionService->getSourceFilename([
                                                                         'cid' => $oldFile->getSubmission()->getCid(),
                                                                         'submitid' => $oldFile->getSubmitid(),
                                                                         'teamid' => $oldFile->getSubmission()->getTeamid(),
                                                                         'probid' => $oldFile->getSubmission()->getProbid(),
                                                                         'langid' => $oldFile->getSubmission()->getLangid(),
                                                                         'rank' => $oldFile->getRank(),
                                                                         'filename' => $oldFile->getFilename()
                                                                     ]);

        // TODO: remove this if we have access to domserver-static everywhere
        $dir = realpath(sprintf('%s/../../etc', $this->kernel->getRootDir()));
        require_once $dir . '/domserver-static.php';

        $difftext = Utils::createDiff(
            $newFile,
            SUBMITDIR . '/' . $newsourcefile,
            $oldFile,
            SUBMITDIR . '/' . $oldsourcefile,
            $this->domjudge->getDomjudgeTmpDir()
        );

        return $this->parseSourceDiff($difftext);
    }

    /**
     * Print the start time of the given contest
     * @param Contest $contest
     * @return string
     * @throws \Exception
     */
    public function printContestStart(Contest $contest): string
    {
        $res = "scheduled to start ";
        if (!$contest->getStarttimeEnabled()) {
            $res = "start delayed, was scheduled ";
        }
        if ($this->printtime(Utils::now(), '%Y%m%d') == $this->printtime($contest->getStarttime(), '%Y%m%d')) {
            // Today
            $res .= "at " . $this->printtime($contest->getStarttime());
        } else {
            // Print full date
            $res .= "on " . $this->printtime($contest->getStarttime(), '%a %d %b %Y %T %Z');
        }
        return $res;
    }

    /**
     * Determine whether the given asset exists
     * @param string $asset
     * @return bool
     */
    public function assetExists(string $asset): bool
    {
        $webDir = realpath(sprintf('%s/../web', $this->kernel->getRootDir()));
        return is_readable($webDir . '/' . $asset);
    }

    /**
     * Print the relative time in h:mm:ss[.uuuuuu] format.
     * @param float $relativeTime
     * @param bool  $useMicroseconds
     * @return string
     */
    public function printTimeRelative(float $relativeTime, bool $useMicroseconds = false): string
    {
        $sign         = $relativeTime < 0 ? '-' : '';
        $relativeTime = abs($relativeTime);
        $fracString   = '';

        if ($useMicroseconds) {
            $fracString   = explode('.', sprintf('%.6f', $relativeTime))[1];
            $relativeTime = (int)floor($relativeTime);
        } else {
            // For negative times we still want to floor, but we've
            // already removed the sign, so take ceil() if negative.
            $relativeTime = (int)($sign == '-' ? ceil($relativeTime) : floor($relativeTime));
        }

        $h            = (int)floor($relativeTime / 3600);
        $relativeTime %= 3600;

        $m            = (int)floor($relativeTime / 60);
        $relativeTime %= 60;

        $s = (int)$relativeTime;

        if ($useMicroseconds) {
            $s .= '.' . $fracString;
        }

        return sprintf($sign . '%01d:%02d:%02d' . $fracString, $h, $m, $s);
    }

    /**
     * Display the scoretime for the given time
     * @param string|float $time
     * @return int
     * @throws \Exception
     */
    public function scoreTime($time)
    {
        return Utils::scoretime($time, (bool)$this->domjudge->dbconfig_get('score_in_seconds', false));
    }

    /**
     * Calculate the penalty time for the given data
     * @param bool $solved
     * @param int  $num_submissions
     * @return int
     * @throws \Exception
     */
    public function calculatePenaltyTime(bool $solved, int $num_submissions)
    {
        return Utils::calcPenaltyTime($solved, $num_submissions, (int)$this->domjudge->dbconfig_get('penalty_time', 20),
                                      (bool)$this->domjudge->dbconfig_get('score_in_seconds', false));
    }

    /**
     * Print the given description, collapsing it by default if it is too big
     * @param string|null $description
     * @return string
     */
    public function descriptionExpand(string $description = null): string
    {
        if ($description == null) {
            return '';
        }
        $descriptionLines = explode("\n", $description);
        if (count($descriptionLines) <= 3) {
            return implode('<br>', $descriptionLines);
        } else {
            $default         = implode('<br>', array_slice($descriptionLines, 0, 3));
            $defaultEscaped  = Utils::specialchars($default);
            $expandedEscaped = Utils::specialchars(implode('<br>', $descriptionLines));
            return <<<EOF
<span>
    <span data-expanded="$expandedEscaped" data-collapsed="$defaultEscaped">
    $default
    </span>
    <br/>
    <a href="javascript:;" onclick="toggleExpand(event)">[expand]</a>
</span>
EOF;
        }
    }

    /**
     * Whether to show the external ID for the given entity
     * @param object|string $entity
     * @return bool
     * @throws \Exception
     */
    public function showExternalId($entity): Bool
    {
        return $this->eventLogService->externalIdFieldForEntity($entity) !== null;
    }

    /**
     * Wrap unquoted text
     * @param string $text
     * @param int    $width
     * @param string $quote
     * @return string
     */
    public function wrapUnquoted(string $text, int $width = 75, string $quote = '>'): string
    {
        $lines = explode("\n", $text);

        $result   = '';
        $unquoted = '';

        foreach ($lines as $line) {
            // Check for quoted lines
            if (strspn($line, $quote) > 0) {
                // First append unquoted text wrapped, then quoted line:
                $result   .= wordwrap($unquoted, $width);
                $unquoted = '';
                $result   .= $line . "\n";
            } else {
                $unquoted .= $line . "\n";
            }
        }

        $result .= wordwrap(rtrim($unquoted), $width);

        return $result;
    }
}
