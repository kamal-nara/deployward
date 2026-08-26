<?php

namespace Deployward\Cron;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Notify\NotifierInterface;

final class TokenWatch
{
    const HOOK = 'deployward_token_watch';
    const INTERVAL = 'daily';
    const STAGE_EXPIRED = 'expired';
    const STAGE_ONE_DAY = '1d';
    const STAGE_FOURTEEN_DAYS = '14d';

    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var NotifierInterface */
    private $notifier;
    /** @var string */
    private $adminUrl;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        NotifierInterface $notifier,
        string $adminUrl
    ) {
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->adminUrl = $adminUrl;
    }

    public function run(?\DateTimeImmutable $now = null): void
    {
        $moment = $now !== null ? $now : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->repository->all() as $deployment) {
            $this->watch($deployment, $moment);
        }
    }

    private function watch(Deployment $deployment, \DateTimeImmutable $now): void
    {
        if ($deployment->visibility() !== 'private') {
            return;
        }
        $expires = Deployment::parseTimestamp($deployment->tokenExpiresAt());
        if ($expires === null) {
            return;
        }
        $stage = self::dueStage($expires, $now);
        if ($stage === '' || self::alreadyAlerted($deployment, $stage)) {
            return;
        }
        $this->notifier->notify(
            $this->subject($deployment, $expires, $stage),
            $this->body($deployment, $expires, $stage),
            NotifierInterface::KIND_ALERT
        );
        $this->repository->recordTokenState($deployment->id(), array(
            'token_alerted' => self::appendStage($deployment->tokenAlerted(), $stage),
        ));
    }

    private static function dueStage(\DateTimeImmutable $expires, \DateTimeImmutable $now): string
    {
        if ($expires <= $now) {
            return self::STAGE_EXPIRED;
        }
        if ($expires <= $now->add(new \DateInterval('P1D'))) {
            return self::STAGE_ONE_DAY;
        }
        if ($expires <= $now->add(new \DateInterval('P' . Deployment::EXPIRY_WARNING_DAYS . 'D'))) {
            return self::STAGE_FOURTEEN_DAYS;
        }

        return '';
    }

    private static function alreadyAlerted(Deployment $deployment, string $stage): bool
    {
        return in_array($stage, explode(',', $deployment->tokenAlerted()), true);
    }

    private static function appendStage(string $alerted, string $stage): string
    {
        return trim($alerted . ',' . $stage, ',');
    }

    private function subject(Deployment $deployment, \DateTimeImmutable $expires, string $stage): string
    {
        $verb = $stage === self::STAGE_EXPIRED ? 'expired on' : 'expires on';

        return sprintf(
            '[Deployward] Token for %s %s %s',
            $deployment->repo(),
            $verb,
            self::readableDate($expires)
        );
    }

    private function body(Deployment $deployment, \DateTimeImmutable $expires, string $stage): string
    {
        $lead = $stage === self::STAGE_EXPIRED
            ? sprintf('The GitHub token for %s expired on %s, so deploys are failing.', $deployment->repo(), self::readableDate($expires))
            : sprintf('The GitHub token for %s expires on %s. Deploys will start failing then.', $deployment->repo(), self::readableDate($expires));

        return $lead . "\n\n"
            . "To replace it:\n"
            . "1. Create a new token on GitHub (Settings, Developer settings, Personal access tokens) with read access to the repository.\n"
            . "2. Open " . $this->adminUrl . "\n"
            . "3. Edit the deployment, paste the new token, and save.\n"
            . "4. Press Check token to confirm GitHub accepts it.\n";
    }

    private static function readableDate(\DateTimeImmutable $moment): string
    {
        return wp_date('j M Y', $moment->getTimestamp());
    }
}
