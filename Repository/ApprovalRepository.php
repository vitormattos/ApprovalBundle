<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ApprovalBundle\Repository;

use App\Entity\Timesheet;
use App\Entity\User;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\ApprovalBundle\Entity\Approval;
use KimaiPlugin\ApprovalBundle\Entity\ApprovalHistory;
use KimaiPlugin\ApprovalBundle\Entity\ApprovalStatus;
use KimaiPlugin\ApprovalBundle\Enumeration\ConfigEnum;
use KimaiPlugin\ApprovalBundle\Toolbox\Formatting;
use KimaiPlugin\ApprovalBundle\Toolbox\SettingsTool;
use KimaiPlugin\MetaFieldsBundle\Repository\MetaFieldRuleRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method Approval|null find($id, $lockMode = null, $lockVersion = null)
 * @method Approval|null findOneBy(array $criteria, array $orderBy = null)
 * @method Approval[] findAll()
 * @method Approval[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApprovalRepository extends ServiceEntityRepository
{
    /**
     * @var SettingsTool
     */
    private $settingsTool;

    /**
     * @var MetaFieldRuleRepository
     */
    private $metaFieldRuleRepository;

    /**
     * @var Formatting
     */
    private $formatting;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    public function __construct(
        ManagerRegistry $registry,
        MetaFieldRuleRepository $metaFieldRuleRepository,
        SettingsTool $settingsTool,
        Formatting $formatting,
        UrlGeneratorInterface $urlGenerator
    ) {
        parent::__construct($registry, Approval::class);
        $this->metaFieldRuleRepository = $metaFieldRuleRepository;
        $this->settingsTool = $settingsTool;
        $this->formatting = $formatting;
        $this->urlGenerator = $urlGenerator;
    }

    public function createApproval(string $data, User $user): ?Approval
    {
        $startDate = new DateTime($data);
        $endDate = (clone $startDate)->modify('next sunday');

        $approval = $this->checkLastStatus($startDate, $endDate, $user, ApprovalStatus::NOT_SUBMITTED, new Approval());

        if ($approval) {
            $approval->setUser($user);
            $approval->setCreationDate(new DateTime());
            $approval->setStartDate($startDate);
            $approval->setEndDate($endDate);
            $approval->setExpectedDuration($this->calculateExpectedDurationByUserAndDate($user, $startDate, $endDate));

            $this->getEntityManager()->persist($approval);
            $this->getEntityManager()->flush();
        }

        return $approval;
    }

    public function calculateExpectedDurationByUserAndDate($user, $startDate, $endDate): int
    {
        $expected = 0;
        for ($i = clone $startDate; $i <= clone $endDate; $i->modify('+1 day')) {
            $expected = $this->getExpectTimeForDate($i, $user, $expected);
        }

        return $expected;
    }

    private function getExpectTimeForDate(DateTime $i, $user, $expected)
    {
        switch ($i->format('N')) {
            case (1):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_MONDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (2):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_TUESDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (3):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_WEDNESDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (4):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_THURSDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (5):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_FRIDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (6):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_SATURDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
            case (7):
                $metaField = $this->metaFieldRuleRepository->find(
                    $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EXPECTED_WORKING_TIME_ON_SUNDAY)
                );
                $expected += $user->getPreferenceValue($metaField ? $metaField->getName() : 0);
                break;
        }

        return $expected;
    }

    public function findApprovalForUser(User $user, DateTime $begin, DateTime $end): ?Approval
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('ap')
            ->from(Approval::class, 'ap')
            ->andWhere('ap.user = :user')
            ->andWhere('ap.startDate = :begin')
            ->andWhere('ap.endDate = :end')
            ->setParameter('user', $user)
            ->setParameter('begin', $begin->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('ap.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllWeek(?array $users): ?array
    {
        $parseToViewArray = $this->getUserApprovals($users);
        $parseToViewArray = $this->addAllNotSubmittedUsers($parseToViewArray, $users);

        $result = $parseToViewArray ? $this->sort($parseToViewArray) : [];

        return $this->getNewestPerUser($result);
    }

    private function deleteHistoryFromArray(array $array): array
    {
        $toReturn = [];
        $tmpElement = $array[0];
        foreach ($array as $element) {
            if ($tmpElement['user'] !== $element['user'] || $tmpElement['startDate'] !== $element['startDate']) {
                $toReturn[] = $tmpElement;
            }
            $tmpElement = $element;
        }
        $toReturn[] = $tmpElement;

        return $toReturn;
    }

    private function parseHistoryToOneElement($approvedList): void
    {
        array_map(function (Approval $item) {
            return $this->getLastHistory($item);
        }, $approvedList);
    }

    private function parseToViewArray($approvedList)
    {
        return array_reduce($approvedList, function ($current, Approval $item) {
            if (!empty($item->getHistory())) {
                $current[] =
                    [
                        'userId' => $item->getUser()->getId(),
                        'startDate' => $item->getStartDate()->format('Y-m-d'),
                        'user' => $item->getUser()->getUsername(),
                        'week' => $this->formatting->parseDate(clone $item->getStartDate()),
                        'status' => $item->getHistory()[0]->getStatus()->getName()
                    ];
            }

            return $current;
        }, []);
    }

    private function parseHistoryToOneElementCurrentWeek($approvedList): void
    {
        array_map(function (Approval $item) {
            $history = $item->getHistory();
            $item->setHistory([empty($history) ? null : $history[\count($history) - 1]]);

            return $item;
        }, $approvedList);
    }

    public function getWeeks(User $user): array
    {
        $approvedWeeks = $this->getApprovedWeeks($user);

        $weeks = [];
        $freeDays = $this->settingsTool->getConfiguration(ConfigEnum::CUSTOMER_FOR_FREE_DAYS);

        $firstDayWorkQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from(Timesheet::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.begin', 'ASC')
            ->setMaxResults(1);
        if (!empty($freeDays)) {
            $firstDayWorkQuery = $firstDayWorkQuery
                ->join('t.project', 'p')
                ->join('p.customer', 'c')
                ->andWhere('c.id != :customerId')
                ->setParameter('customerId', $freeDays);
        }
        $firstDayWork = $firstDayWorkQuery
            ->getQuery()
            ->getResult();
        $firstDay = $firstDayWork ? $firstDayWork[0]->getBegin() : new DateTime('today');

        if ($firstDay->format('D') !== 'Mon') {
            $firstDay = clone new DateTime($firstDay->modify('last monday')->format('Y-m-d H:i:s'));
        }
        while ($firstDay < new DateTime('today')) {
            if (!\in_array($firstDay, $approvedWeeks)) {
                $weeks[] = (object) [
                    'label' => $this->formatting->parseDate($firstDay),
                    'value' => (clone $firstDay)->format('Y-m-d')
                ];
            }
            $firstDay->modify('next monday');
        }

        array_pop($weeks);

        return $weeks;
    }

    private function getApprovedWeeks(User $user)
    {
        $approval = array_filter(
            $this->findWithHistory($user),
            function (Approval $approval) {
                $history = $approval->getHistory();
                if (!empty($history)) {
                    /** @var ApprovalHistory $approvalHistory */
                    $approvalHistory = $history[\count($history) - 1];

                    return \in_array(
                        $approvalHistory->getStatus()->getName(),
                        [
                            ApprovalStatus::GRANTED,
                            ApprovalStatus::SUBMITTED
                        ]
                    );
                } else {
                    return false;
                }
            }
        );

        return array_reduce(
            $approval,
            function ($array, $value) {
                $array[] = $value->getStartDate();

                return $array;
            },
            []
        );
    }

    private function findWithHistory(User $user)
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(Approval::class, 'a')
            ->join('a.history', 'ah')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ah.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function addAllNotSubmittedUsers($parseToViewArray, array $users)
    {
        $usedUsersWeeks = array_map(function ($approve) {
            return $approve['userId'] . '-' . $approve['startDate'];
        }, $parseToViewArray ?: []);
        foreach ($users as $user) {
            $weeks = $this->getWeeks($user);
            foreach ($weeks as $week) {
                if (!\in_array($user->getId() . '-' . $week->value, $usedUsersWeeks)) {
                    $parseToViewArray[] =
                        [
                            'userId' => $user->getId(),
                            'startDate' => $week->value,
                            'user' => $user->getUsername(),
                            'week' => $week->label,
                            'status' => ApprovalStatus::NOT_SUBMITTED
                        ];
                }
            }
        }

        return $parseToViewArray;
    }

    public function findHistoryForUserAndWeek($userId, $week)
    {
        $em = $this->getEntityManager();

        return $em->createQueryBuilder()
            ->select('ap')
            ->from(Approval::class, 'ap')
            ->join('ap.user', 'u')
            ->join('ap.history', 'ah')
            ->where('ap.startDate = :startDate')
            ->andWhere('u.id = :userId')
            ->setParameter('startDate', $week)
            ->setParameter('userId', $userId)
            ->orderBy('ah.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function sort($addNotSubmittedUsers)
    {
        usort($addNotSubmittedUsers, function ($approveA, $approvalB) {
            if ($approveA['startDate'] < $approvalB['startDate']) {
                return -1;
            } elseif ($approveA['startDate'] === $approvalB['startDate']) {
                return strcmp(strtoupper($approveA['user']), strtoupper($approvalB['user']));
            }

            return 1;
        });

        return $addNotSubmittedUsers;
    }

    public function getNewestPerUser(?array $array): ?array
    {
        $arrayToReturn = [];
        if ($array) {
            $tmp_element = $array[0];
            foreach ($array as $value) {
                if ($tmp_element['user'] !== $value['user']) {
                    $arrayToReturn[] = $tmp_element;
                }
                if ($tmp_element['user'] === $value['user'] && $tmp_element['startDate'] !== $value['startDate']) {
                    $arrayToReturn[] = $tmp_element;
                }
                $tmp_element = $value;
            }
            $arrayToReturn[] = $tmp_element;
        }

        return $arrayToReturn;
    }

    public function findCurrentWeekToApprove(array $users, UserInterface $currentUser): int
    {
        $usersId = array_map(function ($user) {
            return $user->getId();
        }, $users);
        $em = $this->getEntityManager()->createQueryBuilder();
        $expr = $em->expr();
        $approvedList = $em
            ->select('ap')
            ->from(Approval::class, 'ap')
            ->join('ap.user', 'u')
            ->join('ap.history', 'ah')
            ->andWhere($expr->in('u.id', ':users'))
            ->setParameter('users', $usersId)
            ->getQuery()
            ->getResult();

        $this->parseHistoryToOneElementCurrentWeek($approvedList);

        $array_filter = array_filter($approvedList, function ($approval) {
            /* @var Approval $approval */
            return !empty($approval->getHistory()) && !empty($approval->getHistory()[0]) && $approval->getHistory()[0]->getStatus()->getName() === ApprovalStatus::SUBMITTED;
        });
        $toReturn = [];
        foreach ($array_filter as $approval) {
            if (!(\in_array('ROLE_TEAMLEAD', $approval->getUser()->getRoles()) && $approval->getUser()->getId() === $currentUser->getId())) {
                $toReturn[] = $approval;
            }
        }

        return \count($toReturn);
    }

    public function areAllUsersApproved($date, $users): bool
    {
        $users = array_reduce($users, function ($current, User $user) {
            $current[] = $user->getUsername();

            return $current;
        });
        $month = (new DateTime($date))->modify('first day of this month');
        $startMonth = (new DateTime($date));
        if ($month->format('N') !== '1') {
            $month->modify('next monday');
        }
        while ($this->isTheSameMonthAndPastDate($month, $startMonth)) {
            $pastRows = $this->getWeekUserList($month);
            if (!empty(array_diff($users, array_column($pastRows, 'user')))) {
                return false;
            }
            $month->modify('next monday');
        }

        return true;
    }

    public function filterPastWeeksNotApproved($parseToViewArray): array
    {
        return array_reduce(
            $parseToViewArray,
            function ($response, $approve, $initial = []) {
                if (
                    \in_array(
                        $approve['status'],
                        [
                            ApprovalStatus::SUBMITTED,
                            ApprovalStatus::NOT_SUBMITTED,
                            ApprovalStatus::DENIED
                        ]
                    )
                ) {
                    $response[] = $approve;
                }

                return $response;
            },
            []
        );
    }

    public function filterWeeksNotSubmitted($parseToViewArray): array
    {
        return array_reduce(
            $parseToViewArray,
            function ($response, $approve) {
                if (\in_array($approve['status'], [ApprovalStatus::NOT_SUBMITTED])) {
                    $response[] = $approve;
                }

                return $response;
            },
            []
        );
    }

    private function getWeekUserList($month): ?array
    {
        $week = $this->findBy(['startDate' => $month], ['startDate' => 'ASC', 'user' => 'ASC']);
        $this->parseHistoryToOneElement($week);
        $parseToViewArray = $this->parseToViewArray($week);

        return array_filter($this->getNewestPerUser($parseToViewArray), function ($user) {
            return $user['status'] === ApprovalStatus::APPROVED;
        });
    }

    private function isTheSameMonthAndPastDate($month, $startMonth): bool
    {
        $monthName = (clone $month)->format('F');
        $startMonthName = (clone $startMonth)->format('F');
        $now = new DateTime('now');

        return $monthName == $startMonthName && $month->format('Y-m-d') < $now->format('Y-m-d');
    }

    private function generateURLtoApprovals(array $approvals): array
    {
        foreach ($approvals as &$approval) {
            $approval['url'] = $this->getUrl($approval['userId'], $approval['startDate']);
        }

        return $approvals;
    }

    public function getUrl(string $userId, string $date): string
    {
        $url = $this->settingsTool->getConfiguration(ConfigEnum::META_FIELD_EMAIL_LINK_URL);
        $path = $this->urlGenerator->generate('approval_bundle_report', [
            'user' => $userId,
            'date' => $date
        ], UrlGeneratorInterface::ABSOLUTE_PATH);

        return rtrim($url, '/') . $path;
    }

    public function getAllNotSubmittedApprovals(array $users): array
    {
        $allRows = $this->findAllWeek($users);
        $allRows = $this->filterWeeksNotSubmitted($allRows);
        $approvals = $this->generateURLtoApprovals($allRows);

        return array_reduce(
            $approvals,
            function ($result, $approval) {
                $result[$approval['userId']][] = $approval;

                return $result;
            },
            []
        );
    }

    public function getUserApprovals(?array $users)
    {
        $usersId = array_map(function ($user) {
            return $user->getId();
        }, $users);

        $em = $this->getEntityManager();
        $approvedList = $em->createQueryBuilder()
            ->select('ap')
            ->from(Approval::class, 'ap')
            ->join('ap.user', 'u')
            ->andWhere($em->getExpressionBuilder()->in('u.id', $usersId))
            ->orderBy('ap.startDate', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->addOrderBy('ap.creationDate', 'ASC')
            ->groupBy('ap')
            ->getQuery()
            ->getResult();

        $this->parseHistoryToOneElement($approvedList);
        $parseToViewArray = $this->parseToViewArray($approvedList);
        if ($parseToViewArray) {
            $parseToViewArray = $this->deleteHistoryFromArray($parseToViewArray);
        }

        return $parseToViewArray;
    }

    private function getLastHistory(Approval $item)
    {
        $history = $item->getHistory();
        if (!empty($history)) {
            $item->setHistory([$history[\count($history) - 1]]);
        } else {
            $item->setHistory([]);
        }

        return $item;
    }

    public function checkLastStatus(DateTime $startDate, $endDate, User $user, string $seededStatus, Approval $approval): ?Approval
    {
        $oldApproval = $this->findOneBy(['startDate' => $startDate, 'endDate' => $endDate, 'user' => $user], ['id' => 'DESC']);
        if ($oldApproval) {
            $oldApproval = $this->getLastHistory($oldApproval);
            if ($oldApproval->getHistory()[0]->getStatus()->getName() !== $seededStatus) {
                return null;
            }
        }

        return $approval;
    }

    public function getNextApproveWeek(User $user): ?string
    {
        $allRows = $this->findAllWeek([$user]);
        $allNotSubmittedRows = $this->filterWeeksNotSubmitted($allRows);         

        // When there are past/current not submitted rows, return that date
        if (!empty($allNotSubmittedRows)){
            return $allNotSubmittedRows[0]['startDate'];
        }

        // If there are no initial values, return nothing
        if (empty($allRows)){
            return null;
        }

        // Otherwise, when there are $allRows, get the one which would be next (located in the future)
        $prevWeekDay = end($allRows)['startDate'];
        return date('Y-m-d', strtotime($prevWeekDay. ' + 7 days'));
    }
}