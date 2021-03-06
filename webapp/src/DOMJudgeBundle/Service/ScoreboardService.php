<?php declare(strict_types=1);

namespace DOMJudgeBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Entity\ContestProblem;
use DOMJudgeBundle\Entity\Judging;
use DOMJudgeBundle\Entity\Problem;
use DOMJudgeBundle\Entity\RankCache;
use DOMJudgeBundle\Entity\ScoreCache;
use DOMJudgeBundle\Entity\Submission;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Entity\TeamAffiliation;
use DOMJudgeBundle\Entity\TeamCategory;
use DOMJudgeBundle\Utils\FreezeData;
use DOMJudgeBundle\Utils\Scoreboard\Filter;
use DOMJudgeBundle\Utils\Scoreboard\Scoreboard;
use DOMJudgeBundle\Utils\Scoreboard\SingleTeamScoreboard;
use DOMJudgeBundle\Utils\Scoreboard\TeamScore;
use DOMJudgeBundle\Utils\Utils;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ScoreboardService
 *
 * Service for scoreboard-related functions
 *
 * @package DOMJudgeBundle\Service
 */
class ScoreboardService
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ScoreboardService constructor.
     * @param EntityManagerInterface $entityManager
     * @param DOMJudgeService        $DOMJudgeService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        DOMJudgeService $DOMJudgeService,
        LoggerInterface $logger
    ) {
        $this->entityManager   = $entityManager;
        $this->DOMJudgeService = $DOMJudgeService;
        $this->logger          = $logger;
    }

    /**
     * Get scoreboard data based on the cached data in the scorecache table
     *
     * @param Contest     $contest     The contest to get the scoreboard for
     * @param bool        $jury        If true, the scoreboard will always be current. If false, frozen results will
     *                                 not be returned
     * @param Filter|null $filter      Filter to use for the scoreboard
     * @param bool        $visibleOnly Iff $jury is true, determines whether to show non-publicly visible teams
     * @return Scoreboard|null
     * @throws \Exception
     */
    public function getScoreboard(
        Contest $contest,
        bool $jury = false,
        Filter $filter = null,
        bool $visibleOnly = false
    ) {
        $freezeData = new FreezeData($contest);

        // Don't leak information before start of contest
        if (!$freezeData->started() && !$jury) {
            return null;
        }

        $teams      = $this->getTeams($contest, $jury && !$visibleOnly, $filter);
        $problems   = $this->getProblems($contest);
        $categories = $this->getCategories($jury && !$visibleOnly);
        $scoreCache = $this->getScorecache($contest);

        return new Scoreboard($contest, $teams, $categories, $problems, $scoreCache, $freezeData, $jury,
                              (int)$this->DOMJudgeService->dbconfig_get('penalty_time', 20),
                              (bool)$this->DOMJudgeService->dbconfig_get('score_in_seconds', false));
    }

    /**
     * Get scoreboard data for a single team based on the cached data in the scorecache table
     *
     * @param Contest $contest The contest to get the scoreboard for
     * @param int     $teamId  The ID of the team to get the scoreboard for
     * @param bool    $jury    If true, the scoreboard will always be current. If false, frozen results will not be
     *                         returned
     * @return Scoreboard|null
     * @throws \Exception
     */
    public function getTeamScoreboard(Contest $contest, int $teamId, bool $jury = false)
    {
        $freezeData = new FreezeData($contest);

        // Don't leak information before start of contest
        if (!$freezeData->started()) {
            return null;
        }

        $teams = $this->getTeams($contest, true, new Filter([], [], [], [$teamId]));
        if (empty($teams)) {
            return null;
        }
        $team       = reset($teams);
        $problems   = $this->getProblems($contest);
        $rankCache  = $this->getRankcache($contest, $team);
        $scoreCache = $this->getScorecache($contest, $team);
        if ($jury || !$freezeData->showFrozen()) {
            $teamRank = $this->calculateTeamRank($contest, $team, $rankCache, $freezeData, $jury);
        } else {
            $teamRank = 0;
        }

        return new SingleTeamScoreboard($contest, $team, $teamRank, $problems, $rankCache, $scoreCache, $freezeData,
                                        $jury,
                                        (int)$this->DOMJudgeService->dbconfig_get('penalty_time', 20),
                                        (bool)$this->DOMJudgeService->dbconfig_get('score_in_seconds', false));
    }

    /**
     * Calculate the rank for a single team based on the cache tables
     *
     * @param Contest         $contest
     * @param Team            $team
     * @param RankCache|null  $rankCache
     * @param FreezeData|null $freezeData
     * @param bool            $jury
     * @return int
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function calculateTeamRank(
        Contest $contest,
        Team $team,
        RankCache $rankCache = null,
        FreezeData $freezeData = null,
        bool $jury = false
    ) {
        if ($freezeData === null) {
            $freezeData = new FreezeData($contest);
        }
        if ($rankCache === null) {
            $rankCache = $this->getRankcache($contest, $team);
        }
        $restricted = ($jury || $freezeData->showFinal(false));
        $variant    = $restricted ? 'restricted' : 'public';
        $points     = $rankCache ? $rankCache->getPointsRestricted() : 0;
        $totalTime  = $rankCache ? $rankCache->getTotaltimeRestricted() : 0;
        $sortOrder  = $team->getCategory()->getSortorder();

        // Number of teams that definitely ranked higher
        $better = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:RankCache', 'r')
            ->join('r.team', 't')
            ->join('t.category', 'tc')
            ->select('COUNT(t.teamid)')
            ->andWhere('r.contest = :contest')
            ->andWhere('tc.sortorder = :sortorder')
            ->andWhere('t.enabled = 1')
            ->andWhere(sprintf('r.points_%s > :points OR (r.points_%s = :points AND r.totaltime_%s < :totaltime)',
                               $variant, $variant,
                               $variant))
            ->setParameter(':contest', $contest)
            ->setParameter(':sortorder', $sortOrder)
            ->setParameter(':points', $points)
            ->setParameter(':totaltime', $totalTime)
            ->getQuery()
            ->getSingleScalarResult();

        $rank = $better + 1;

        // Resolve ties based on latest correctness points, only necessary when we actually
        // solved at least one problem, so this list should usually be short
        if ($points > 0) {
            /** @var RankCache[] $tied */
            $tied = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:RankCache', 'r')
                ->join('r.team', 't')
                ->join('t.category', 'tc')
                ->select('r, t')
                ->andWhere('r.contest = :contest')
                ->andWhere('tc.sortorder = :sortorder')
                ->andWhere('t.enabled = 1')
                ->andWhere(sprintf('r.points_%s = :points AND r.totaltime_%s = :totaltime', $variant, $variant))
                ->setParameter(':contest', $contest)
                ->setParameter(':sortorder', $sortOrder)
                ->setParameter(':points', $points)
                ->setParameter(':totaltime', $totalTime)
                ->getQuery()
                ->getResult();

            // All teams that are tied for this position, in most cases this will only be the team we are finding the rank for,
            // only retrieve rest of the data when there are actual ties
            if (count($tied) > 1) {
                // Initialize team scores for each team
                /** @var TeamScore[] $teamScores */
                $teamScores = [];
                $teams      = [];
                foreach ($tied as $rankCache) {
                    $teamScores[$rankCache->getTeam()->getTeamid()] = new TeamScore($rankCache->getTeam());
                    $teams[]                                        = $rankCache->getTeam();
                }

                // Get submission times for each of the teams
                /** @var ScoreCache[] $tiedScores */
                $tiedScores = $this->entityManager->createQueryBuilder()
                    ->from('DOMJudgeBundle:ScoreCache', 's')
                    ->join('s.problem', 'p')
                    ->join('p.contest_problems', 'cp', Join::WITH, 'cp.contest = :contest')
                    ->select('s')
                    ->andWhere('s.contest = :contest')
                    ->andWhere(sprintf('s.is_correct_%s = 1', $variant))
                    ->andWhere('cp.allowSubmit = 1')
                    ->andWhere('s.team IN (:teams)')
                    ->setParameter(':contest', $contest)
                    ->setParameter(':teams', $teams)
                    ->getQuery()
                    ->getResult();

                foreach ($tiedScores as $tiedScore) {
                    $teamScores[$tiedScore->getTeam()->getTeamid()]->addSolveTime(Utils::scoretime(
                        $tiedScore->getSolveTime($restricted),
                        (bool)$this->DOMJudgeService->dbconfig_get('score_in_seconds', false)
                    ));
                }

                // Now check for each team if it is ranked higher than $teamid
                foreach ($tied as $rankCache) {
                    if ($rankCache->getTeam()->getTeamid() == $team->getTeamid()) {
                        continue;
                    }
                    if (Scoreboard::scoreTiebreaker($teamScores[$rankCache->getTeam()->getTeamid()],
                                                    $teamScores[$team->getTeamid()]) < 0) {
                        $rank++;
                    }
                }
            }
        }

        return $rank;
    }

    /**
     * Scoreboard calculation
     *
     * Given a contest, team and a problem (re)calculate the values for one row in the scoreboard.
     *
     * Due to current transactions usage, this function MUST NOT do anything inside a transaction
     * @param Contest $contest
     * @param Team    $team
     * @param Problem $problem
     * @param bool    $updateRankCache If set to false, do not update the rankcache
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    public function calculateScoreRow(Contest $contest, Team $team, Problem $problem, bool $updateRankCache = true)
    {
        $this->logger->debug(sprintf("ScoreboardService::calculateScoreRow '%d' '%d' '%d'", $contest->getCid(),
                                     $team->getTeamid(),
                                     $problem->getProbid()));

        // First acquire an advisory lock to prevent other calls to this method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d.%d', $contest->getCid(), $team->getTeamid(), $problem->getProbid());
        if ($this->entityManager->getConnection()->fetchColumn('SELECT GET_LOCK(:lock, 3)',
                                                               [':lock' => $lockString]) != 1) {
            throw new \Exception(sprintf("ScoreboardService::calculateScoreRow failed to obtain lock '%s'",
                                         $lockString));
        }

        // Note the clause 's.submittime < c.endtime': this is used to
        // filter out TOO-LATE submissions from pending, but it also means
        // that these will not count as solved. Correct submissions with
        // submittime after contest end should never happen, unless one
        // resets the contest time after successful judging.
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:Submission', 's')
            ->select('s, j, c')
            ->leftJoin('s.contest', 'c')
            ->leftJoin('s.judgings', 'j', Join::WITH, 'j.valid = 1')
            ->andWhere('s.teamid = :teamid')
            ->andWhere('s.probid = :probid')
            ->andWhere('s.cid = :cid')
            ->andWhere('s.valid = 1')
            ->andWhere('s.submittime < c.endtime')
            ->setParameter(':teamid', $team->getTeamid())
            ->setParameter(':probid', $problem->getProbid())
            ->setParameter(':cid', $contest->getCid())
            ->orderBy('s.submittime');

        if (!$this->DOMJudgeService->dbconfig_get('compile_penalty', true)) {
            $queryBuilder
                ->andWhere('j.result IS NULL or j.result != :compileError')
                ->setParameter(':compileError', Judging::RESULT_COMPILER_ERROR);
        }

        /** @var Submission[] $submissions */
        $submissions = $queryBuilder->getQuery()->getResult();

        $verificationRequired = $this->DOMJudgeService->dbconfig_get('verification_required', false);

        // Initialize variables
        $submissionsJury = $pendingJury = $timeJury = 0;
        $submissionsPubl = $pendingPubl = $timePubl = 0;
        $correctJury     = false;
        $correctPubl     = false;

        foreach ($submissions as $submission) {
            /** @var Judging|null $judging */
            $judging    = $submission->getJudgings()->first() ?: null;
            $absSubmitTime = (float)$submission->getSubmittime();
            $submitTime = $contest->getContestTime($absSubmitTime);

            // Check if this submission has a publicly visible judging result:
            if ($judging === null || ($verificationRequired && !$judging->getVerified()) || empty($judging->getResult())) {
                $pendingJury++;
                $pendingPubl++;
                // Don't do any more counting for this submission.
                continue;
            }

            $submissionsJury++;
            if ($submission->isAfterFreeze()) {
                // Show submissions after freeze as pending to the public (if SHOW_PENDING is enabled):
                $pendingPubl++;
            } else {
                $submissionsPubl++;
            }

            // if correct, don't look at any more submissions after this one
            if ($judging->getResult() == Judging::RESULT_CORRECT) {
                $correctJury = true;
                $timeJury    = $submitTime;
                if (!$submission->isAfterFreeze()) {
                    $correctPubl = true;
                    $timePubl    = $submitTime;
                }
                // stop counting after a first correct submission
                break;
            }
        }

        // See if this submission was the first to solve this problem
        // (only relevant if it was correct in the first place)
        $firstToSolve = false;
        if ($correctJury) {
            $params = [
                ':cid' => $contest->getCid(),
                ':probid' => $problem->getProbid(),
                ':teamSortOrder' => $team->getCategory()->getSortorder(),
                ':submitTime' => $absSubmitTime,
                ':correctResult' => Judging::RESULT_CORRECT,
            ];

            // Find out how many valid submissions were submitted earlier
            // that have a valid judging that is correct, or are awaiting judgement.
            // Only if there are 0 found, we are definitely the first to solve this problem.
	    // To find relevant submissions/judgings:
	    // - submission needs to be valid (not invalidated)
	    // - a judging is present, but
	    //   - it's not part of a rejudging
	    //   - either it's still ongoing (pending judgement, could be correct)
	    //   - or already judged to be correct (if it's judged but != correct, it's not a first to solve)
	    // - or the submission is still queued for judgement (judgehost is NULL).
            $firstToSolve = 0 == $this->entityManager->getConnection()->fetchColumn('
                SELECT count(*) FROM submission s
                    LEFT JOIN judging j USING (submitid)
                    LEFT JOIN team t USING(teamid)
                    LEFT JOIN team_category tc USING (categoryid)
                WHERE s.valid = 1 AND
                    ((j.valid = 1 AND ( j.rejudgingid IS NULL AND (j.result IS NULL OR j.result = :correctResult))) OR
                      s.judgehost IS NULL) AND
                    s.cid = :cid AND s.probid = :probid AND
                    tc.sortorder = :teamSortOrder AND
                    round(s.submittime,4) < :submitTime',
                $params);
        }

        // Use a direct REPLACE INTO query to drastically speed this up
        $params = [
            ':cid' => $contest->getCid(),
            ':teamid' => $team->getTeamid(),
            ':probid' => $problem->getProbid(),
            ':submissionsRestricted' => $submissionsJury,
            ':pendingRestricted' => $pendingJury,
            ':solvetimeRestricted' => (int)$timeJury,
            ':isCorrectRestricted' => (int)$correctJury,
            ':submissionsPublic' => $submissionsPubl,
            ':pendingPublic' => $pendingPubl,
            ':solvetimePublic' => (int)$timePubl,
            ':isCorrectPublic' => (int)$correctPubl,
            ':isFirstToSolve' => (int)$firstToSolve,
        ];
        $this->entityManager->getConnection()->executeQuery('REPLACE INTO scorecache
            (cid, teamid, probid,
             submissions_restricted, pending_restricted, solvetime_restricted, is_correct_restricted,
             submissions_public, pending_public, solvetime_public, is_correct_public, is_first_to_solve)
            VALUES (:cid, :teamid, :probid, :submissionsRestricted, :pendingRestricted, :solvetimeRestricted, :isCorrectRestricted,
            :submissionsPublic, :pendingPublic, :solvetimePublic, :isCorrectPublic, :isFirstToSolve)', $params);

        if ($this->entityManager->getConnection()->fetchColumn('SELECT RELEASE_LOCK(:lock)',
                                                               [':lock' => $lockString]) != 1) {
            throw new \Exception('ScoreboardService::calculateScoreRow failed to release lock');
        }

        // If we found a new correct result, update the rank cache too
        if ($updateRankCache && ($correctJury || $correctPubl)) {
            $this->updateRankCache($contest, $team);
        }
    }

    /**
     * Update tables used for efficiently computing team ranks
     *
     * Given a contest and team (re)calculate the time and solved problems for a team.
     *
     * Due to current transactions usage, this function MUST NOT do anything inside a transaction
     * @param Contest $contest
     * @param Team    $team
     * @throws \Exception
     */
    public function updateRankCache(Contest $contest, Team $team)
    {
        $this->logger->debug(sprintf("ScoreboardService::updateRankCache '%d' '%d'", $contest->getCid(),
                                     $team->getTeamid()));

        // First acquire an advisory lock to prevent other calls to this method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d', $contest->getCid(), $team->getTeamid());
        if ($this->entityManager->getConnection()->fetchColumn('SELECT GET_LOCK(:lock, 3)',
                                                               [':lock' => $lockString]) != 1) {
            throw new \Exception(sprintf("ScoreboardService::updateRankCache failed to obtain lock '%s'", $lockString));
        }

        // Fetch contest problems. We can not add it as a relation on ScoreCache as Doctrine doesn't seem to like that its keys
        // are part of the primary key
        /** @var ContestProblem[] $contestProblems */
        $contestProblems = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:ContestProblem', 'cp', 'cp.probid')
            ->select('cp')
            ->andWhere('cp.contest = :contest')
            ->setParameter(':contest', $contest)
            ->getQuery()
            ->getResult();

        // Intialize our data
        $variants  = ['public' => false, 'restricted' => true];
        $numPoints = [];
        $totalTime = [];
        foreach ($variants as $variant => $isRestricted) {
            $numPoints[$variant] = 0;
            $totalTime[$variant] = $team->getPenalty();
        }

        $penaltyTime      = (int)$this->DOMJudgeService->dbconfig_get('penalty_time', 20);
        $scoreIsInSeconds = (bool)$this->DOMJudgeService->dbconfig_get('score_in_seconds', false);

        // Now fetch the ScoreCache entries
        /** @var ScoreCache[] $scoreCacheRows */
        $scoreCacheRows = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:ScoreCache', 's')
            ->select('s')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.team = :team')
            ->setParameter(':contest', $contest)
            ->setParameter(':team', $team)
            ->getQuery()
            ->getResult();

        // Process all score cache rows
        foreach ($scoreCacheRows as $scoreCache) {
            foreach ($variants as $variant => $isRestricted) {
                $probId = $scoreCache->getProblem()->getProbid();
                if (isset($contestProblems[$probId]) && $scoreCache->getIsCorrect($isRestricted)) {
                    $penalty = Utils::calcPenaltyTime($scoreCache->getIsCorrect($isRestricted),
                                                      $scoreCache->getSubmissions($isRestricted),
                                                      $penaltyTime, $scoreIsInSeconds);

                    $numPoints[$variant] += $contestProblems[$probId]->getPoints();
                    $totalTime[$variant] += Utils::scoretime((float)$scoreCache->getSolveTime($isRestricted),
                                                             $scoreIsInSeconds) + $penalty;
                }
            }
        }

        // Use a direct REPLACE INTO query to drastically speed this up
        $params = [
            ':cid' => $contest->getCid(),
            ':teamid' => $team->getTeamid(),
            ':pointsRestricted' => $numPoints['restricted'],
            ':totalTimeRestricted' => $totalTime['restricted'],
            ':pointsPublic' => $numPoints['public'],
            ':totalTimePublic' => $totalTime['public'],
        ];
        $this->entityManager->getConnection()->executeQuery('REPLACE INTO rankcache (cid, teamid,
            points_restricted, totaltime_restricted,
            points_public, totaltime_public)
            VALUES (:cid, :teamid, :pointsRestricted, :totalTimeRestricted, :pointsPublic, :totalTimePublic)', $params);

        if ($this->entityManager->getConnection()->fetchColumn('SELECT RELEASE_LOCK(:lock)',
                                                               [':lock' => $lockString]) != 1) {
            throw new \Exception('ScoreboardService::updateRankCache failed to release lock');
        }
    }

    /**
     * Initialize the scoreboard filter for the given request
     * @param Request       $request
     * @param Response|null $response
     * @return Filter
     */
    public function initializeScoreboardFilter(Request $request, Response $response)
    {
        $scoreFilter = [];
        if ($this->DOMJudgeService->getCookie('domjudge_scorefilter')) {
            $scoreFilter = $this->DOMJudgeService->jsonDecode((string)$this->DOMJudgeService->getCookie('domjudge_scorefilter'));
        }

        if ($request->query->has('clear')) {
            $scoreFilter = [];
        }

        if ($request->query->has('filter')) {
            $scoreFilter = [];
            foreach (['affiliations', 'countries', 'categories'] as $type) {
                if ($request->query->has($type)) {
                    $scoreFilter[$type] = $request->query->get($type);
                }
            }
        }

        $this->DOMJudgeService->setCookie('domjudge_scorefilter',
                                          $this->DOMJudgeService->jsonEncode($scoreFilter), 0, null, '', false,
                                          false, $response);

        return new Filter($scoreFilter['affiliations'] ?? [], $scoreFilter['countries'] ?? [],
                          $scoreFilter['categories'] ?? [], $scoreFilter['teams'] ?? []);
    }

    /**
     * Get a list of affiliation names grouped on category name
     * @param Contest $contest
     * @return array
     */
    public function getGroupedAffiliations(Contest $contest)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:TeamCategory', 'cat')
            ->select('cat', 't', 'affil')
            ->leftJoin('cat.teams', 't')
            ->leftJoin('t.affiliation', 'affil')
            ->andWhere('cat.visible = 1')
            ->orderBy('cat.name')
            ->addOrderBy('affil.name');

        if (!$contest->getPublic()) {
            $queryBuilder
                ->join('t.contests', 'c')
                ->andWhere('c = :contest')
                ->setParameter(':contest', $contest);
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();

        $groupedAffiliations = [];
        foreach ($categories as $category) {
            $affiliations = [];
            /** @var Team $team */
            foreach ($category->getTeams() as $team) {
                $affiliations[$team->getAffiliation()->getName()] = $team->getAffiliation()->getName();
            }

            if (!empty($affiliations)) {
                $groupedAffiliations[$category->getName()] = array_values($affiliations);
            }
        }

        return array_chunk($groupedAffiliations, 3, true);
    }

    /**
     * Get values to display in the scoreboard filter
     * @param Contest $contest
     * @param bool    $jury
     * @return array
     * @throws \Exception
     */
    public function getFilterValues(Contest $contest, bool $jury): array
    {
        $filters          = [
            'affiliations' => [],
            'countries' => [],
            'categories' => [],
        ];
        $showFlags        = $this->DOMJudgeService->dbconfig_get('show_flags', true);
        $showAffiliations = $this->DOMJudgeService->dbconfig_get('show_affiliations', true);

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:TeamCategory', 'c')
            ->select('c');
        if (!$jury) {
            $queryBuilder->andWhere('c.visible = 1');
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();
        foreach ($categories as $category) {
            $filters['categories'][$category->getCategoryid()] = $category->getName();
        }

        // show only affiliations / countries with visible teams
        if (empty($categories) || !$showAffiliations) {
            $filters['affiliations'] = [];
        } else {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:TeamAffiliation', 'a')
                ->select('a')
                ->join('a.teams', 't')
                ->andWhere('t.category IN (:categories)')
                ->setParameter(':categories', $categories);
            if (!$contest->getPublic()) {
                $queryBuilder
                    ->join('t.contests', 'c')
                    ->andWhere('c = :contest')
                    ->setParameter(':contest', $contest);
            }

            /** @var TeamAffiliation[] $affiliations */
            $affiliations = $queryBuilder->getQuery()->getResult();
            foreach ($affiliations as $affiliation) {
                $filters['affiliations'][$affiliation->getAffilid()] = $affiliation->getName();
                if ($showFlags && $affiliation->getCountry() !== null) {
                    $filters['countries'][] = $affiliation->getCountry();
                }
            }
        }

        $filters['countries'] = array_unique($filters['countries']);
        sort($filters['countries']);
        asort($filters['affiliations'], SORT_FLAG_CASE);

        return $filters;
    }

    /**
     * Get the scoreboard Twig data for a given contest
     * @param Request      $request
     * @param Response     $response
     * @param string       $refreshUrl
     * @param bool         $jury
     * @param bool         $public
     * @param bool         $static
     * @param Contest|null $contest
     * @return array
     * @throws \Exception
     */
    public function getScoreboardTwigData(
        Request $request,
        Response $response,
        string $refreshUrl,
        bool $jury,
        bool $public,
        bool $static,
        Contest $contest = null
    ) {
        $data = [];
        if ($contest) {
            $data['refresh'] = [
                'after' => 30,
                'url' => $refreshUrl,
                'ajax' => true,
            ];

            $scoreFilter = $this->initializeScoreboardFilter($request, $response);
            $scoreboard  = $this->getScoreboard($contest, $jury, $scoreFilter);

            $data['contest']              = $contest;
            $data['static']               = $static;
            $data['scoreFilter']          = $scoreFilter;
            $data['scoreboard']           = $scoreboard;
            $data['filterValues']         = $this->getFilterValues($contest, $jury);
            $data['groupedAffiliations']  = $this->getGroupedAffiliations($contest);
            $data['showFlags']            = $this->DOMJudgeService->dbconfig_get('show_flags', true);
            $data['showAffiliationLogos'] = $this->DOMJudgeService->dbconfig_get('show_affiliation_logos', false);
            $data['showAffiliations']     = $this->DOMJudgeService->dbconfig_get('show_affiliations', true);
            $data['showPending']          = $this->DOMJudgeService->dbconfig_get('show_pending', false);
            $data['showTeamSubmissions']  = $this->DOMJudgeService->dbconfig_get('show_teams_submissions', true);
            $data['scoreInSeconds']       = $this->DOMJudgeService->dbconfig_get('score_in_seconds', false);
        }

        if ($request->isXmlHttpRequest()) {
            $data['jury']   = $jury;
            $data['public'] = $public;
            $data['ajax']   = true;
        }

        return $data;
    }

    /**
     * Get the teams to display on the scoreboard
     * @param Contest     $contest
     * @param bool        $jury
     * @param Filter|null $filter
     * @return Team[]
     */
    protected function getTeams(Contest $contest, bool $jury = false, Filter $filter = null)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:Team', 't', 't.teamid')
            ->innerJoin('t.category', 'tc')
            ->leftJoin('t.affiliation', 'ta')
            ->select('t, tc, ta')
            ->andWhere('t.enabled = 1');

        if (!$contest->getPublic()) {
            $queryBuilder
                ->join('t.contests', 'c')
                ->andWhere('c.cid = :cid')
                ->setParameter(':cid', $contest->getCid());
        }

        if (!$jury) {
            $queryBuilder->andWhere('tc.visible = 1');
        }

        if ($filter) {
            if ($filter->getAffiliations()) {
                $queryBuilder
                    ->andWhere('t.affilid IN (:affiliations)')
                    ->setParameter(':affiliations', $filter->getAffiliations());
            }

            if ($filter->getCategories()) {
                $queryBuilder
                    ->andWhere('t.categoryid IN (:categories)')
                    ->setParameter(':categories', $filter->getCategories());
            }

            if ($filter->getCountries()) {
                $queryBuilder
                    ->andWhere('ta.country IN (:countries)')
                    ->setParameter(':countries', $filter->getCountries());
            }

            if ($filter->getTeams()) {
                $queryBuilder
                    ->andWhere('t.teamid IN (:teams)')
                    ->setParameter(':teams', $filter->getTeams());
            }
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the problems to display on the scoreboard
     * @param Contest $contest
     * @return ContestProblem[]
     */
    protected function getProblems(Contest $contest)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:ContestProblem', 'cp', 'cp.probid')
            ->select('cp, p')
            ->innerJoin('cp.problem', 'p')
            ->andWhere('cp.allowSubmit = 1')
            ->andWhere('cp.cid = :cid')
            ->setParameter(':cid', $contest->getCid())
            ->orderBy('cp.shortname');

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the categories to display on the scoreboard
     * @param bool $jury
     * @return TeamCategory[]
     */
    protected function getCategories(bool $jury)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:TeamCategory', 'cat', 'cat.categoryid')
            ->select('cat')
            ->orderBy('cat.sortorder')
            ->addOrderBy('cat.name')
            ->addOrderBy('cat.categoryid');

        if (!$jury) {
            $queryBuilder->andWhere('cat.visible = 1');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the scorecache used to calculate the scoreboard
     * @param Contest   $contest
     * @param Team|null $team
     * @return ScoreCache[]
     */
    protected function getScorecache(Contest $contest, Team $team = null)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:ScoreCache', 's')
            ->select('s')
            ->andWhere('s.contest = :contest')
            ->setParameter(':contest', $contest);

        if ($team) {
            $queryBuilder
                ->andWhere('s.team = :team')
                ->setParameter(':team', $team);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the rank cache for the given team
     * @param Contest $contest
     * @param Team    $team
     * @return RankCache|null
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function getRankcache(Contest $contest, Team $team)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:RankCache', 'r')
            ->select('r')
            ->andWhere('r.contest = :contest')
            ->andWhere('r.team = :team')
            ->setParameter(':contest', $contest)
            ->setParameter(':team', $team);

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }
}
