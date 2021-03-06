<?php declare(strict_types=1);

namespace DOMJudgeBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use DOMJudgeBundle\Entity\Role;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Entity\TeamAffiliation;
use DOMJudgeBundle\Entity\TeamCategory;
use DOMJudgeBundle\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class BaylorCmsService
{
    const BASE_URI = 'https://icpc.baylor.edu';
    const WS_TOKEN_URL = '/auth/realms/cm5/protocol/openid-connect/token';
    const WS_CLICS = '/cm5-contest-rest/rest/contest/export/CLICS/CONTEST/';

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var Client
     */
    protected $client;

    /**
     * BaylorCmsService constructor.
     * @param DOMJudgeService        $DOMJudgeService
     * @param EntityManagerInterface $entityManager
     * @param                        $domjudgeVersion
     */
    public function __construct(
        DOMJudgeService $DOMJudgeService,
        EntityManagerInterface $entityManager,
        $domjudgeVersion
    ) {
        $this->DOMJudgeService = $DOMJudgeService;
        $this->entityManager   = $entityManager;
        $this->client          = new Client(
            [
                'http_errors' => false,
                'base_uri' => self::BASE_URI,
                'headers' => [
                    'User-Agent' => 'DOMjudge/' . $domjudgeVersion,
                    'Accept' => 'application/json',
                ]
            ]
        );
    }

    /**
     * Import teams from the Baylor CMS
     * @param string      $token
     * @param string      $contest
     * @param string|null $message
     * @return bool
     */
    public function importTeams(string $token, string $contest, string &$message = null): bool
    {
        $bearerToken = $this->getBearerToken($token, $message);
        if ($bearerToken === null) {
            return false;
        }
        $response = $this->client->get(self::WS_CLICS . $contest, [
            'headers' => [
                'Authorization' => 'bearer ' . $bearerToken
            ],
        ]);

        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            $message = sprintf('Access forbidden, is your token valid? Did you specify the correct contest ID? %s',
                               $response->getBody());
            return false;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $message = sprintf('Unknown error while retrieving data from icpc.baylor.edu, status code: %d, %s',
                               $response->getStatusCode(), $response->getBody());
            return false;
        }

        $body = (string)$response->getBody();
        $json = $this->DOMJudgeService->jsonDecode((string)$body);

        if ($json === null) {
            $message = sprintf('Error retrieving API data. API gave us: %s', $body);
            return false;
        }

        $participants = $this->entityManager->getRepository(TeamCategory::class)->findOneBy(['name' => 'Participants']);
        $teamRole     = $this->entityManager->getRepository(Role::class)->findOneBy(['dj_role' => 'team']);

        foreach ($json['contest']['group'] as $group) {
            $siteName = $group['groupName'];
            foreach ($group['team'] as $teamData) {
                $institutionName = $teamData['institutionName'];
                // Note: affiliations are not updated and not deleted even if all teams have canceled
                $affiliation = $this->entityManager->getRepository(TeamAffiliation::class)->findOneBy(['name' => $institutionName]);
                if ($affiliation === null) {
                    $shortName   = isset($teamData['institutionShortName']) ? $teamData['institutionShortName'] : $institutionName;
                    $affiliation = new TeamAffiliation();
                    $affiliation
                        ->setName($institutionName)
                        ->setShortname($shortName)
                        ->setCountry($teamData['country']);
                    $this->entityManager->persist($affiliation);
                    $this->entityManager->flush();
                }

                /*
                 * FIXME: team members are behind a different API call and not important for now
                 */

                $team = $this->entityManager->getRepository(Team::class)->findOneBy(['externalid' => $teamData['teamId']]);
                // Note: teams are not deleted but disabled depending on their status
                $enabled = $teamData['status'] === 'ACCEPTED';
                if ($team === null) {
                    $team = new Team();
                    $team
                        ->setName($teamData['teamName'])
                        ->setCategory($participants)
                        ->setAffiliation($affiliation)
                        ->setEnabled($enabled)
                        ->setComments('Status: ' . $teamData['status'])
                        ->setExternalid($teamData['teamId'])
                        ->setRoom($siteName);
                    $this->entityManager->persist($team);
                    $this->entityManager->flush();
                    $username = sprintf("team%04d", $team->getTeamid());
                    $user     = new User();
                    $user
                        ->setUsername($username)
                        ->setName($teamData['teamName'])
                        ->setTeam($team)
                        ->addRole($teamRole);

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();
                } else {
                    $username = sprintf("team%04d", $team->getTeamid());
                    $team
                        ->setName($teamData['teamName'])
                        ->setCategory($participants)
                        ->setAffiliation($affiliation)
                        ->setEnabled($enabled)
                        ->setComments('Status: ' . $teamData['status'])
                        ->setExternalid($teamData['teamId'])
                        ->setRoom($siteName);

                    $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
                    if ($user !== null) {
                        $user->setName($teamData['teamName']);
                    }

                    $this->entityManager->flush();
                }
            }
        }

        return true;
    }

    /**
     * Upload standings to the Baylor CMS
     * @param string      $token
     * @param string      $contest
     * @param string|null $message
     * @return bool
     */
    public function uploadStandings(string $token, string $contest, string &$message = null)
    {
        // TODO: reimplement

        $message = 'Sorry, standings upload is broken because of a format change.';
        return false;
    }

    /**
     * Convert the given web service token to a bearer token
     * @param string      $token
     * @param string|null $message
     * @return string|null
     */
    protected function getBearerToken(string $token, string &$message = null)
    {
        $response = $this->client->post(self::WS_TOKEN_URL, [
            RequestOptions::FORM_PARAMS => [
                'client_id' => 'cm5-token',
                'username' => 'token:' . $token,
                'password' => '',
                'grant_type' => 'password'
            ]
        ]);

        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            $message = sprintf('Access forbidden, is your token valid? %s',
                               $response->getBody());
            return null;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $message = sprintf('Unknown error while retrieving data from icpc.baylor.edu, status code: %d, %s',
                               $response->getStatusCode(), $response->getBody());
            return null;
        }

        $body = $this->DOMJudgeService->jsonDecode((string)$response->getBody());
        return $body['access_token'];
    }
}
