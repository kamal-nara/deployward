<?php

namespace Deployward\Security;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Notify\NotifierInterface;

final class TokenReadabilityGuard
{
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

    public function check(Deployment $deployment): bool
    {
        if ($deployment->visibility() !== 'private') {
            return true;
        }
        if (! $deployment->isTokenUnreadable()) {
            $this->clearStaleState($deployment);

            return true;
        }
        if ($deployment->tokenUnreadableAt() === '' && $this->isStillUnannounced($deployment)) {
            $this->announce($deployment);
        }

        return false;
    }

    public static function deployFailureMessage(string $repo): string
    {
        return sprintf(
            'Deployward cannot read the saved GitHub token for %1$s. The key it was locked with is gone: '
            . "the site's WordPress secret keys changed, Deployward's own key was removed, "
            . 'or the token was saved on another site. Deployments of %1$s are stopped until someone '
            . 'pastes the token again in Deployward.',
            $repo
        );
    }

    private function clearStaleState(Deployment $deployment): void
    {
        if ($deployment->tokenUnreadableAt() !== '') {
            $this->repository->recordTokenState($deployment->id(), array('token_unreadable_at' => ''));
        }
    }

    private function isStillUnannounced(Deployment $snapshot): bool
    {
        $fresh = $this->repository->find($snapshot->id());

        return $fresh !== null && $fresh->tokenUnreadableAt() === '';
    }

    private function announce(Deployment $deployment): void
    {
        $this->repository->recordTokenState($deployment->id(), array(
            'token_unreadable_at' => gmdate(\DateTimeInterface::ATOM),
        ));
        $this->notifier->notify(
            '[Deployward] Cannot read the saved GitHub token for ' . $deployment->repo(),
            $this->alertBody($deployment->repo()),
            NotifierInterface::KIND_ALERT
        );
    }

    private function alertBody(string $repo): string
    {
        return sprintf('Deployward can no longer read the saved GitHub token for %1$s.', $repo) . "\n\n"
            . "The key it was locked with is gone: the site's WordPress secret keys changed (older Deployward "
            . "versions locked tokens with them), Deployward's own key was removed or replaced, or the token "
            . "was saved on another site.\n\n"
            . sprintf('Deployments of %1$s are stopped until someone pastes the token again in Deployward:', $repo) . "\n"
            . "1. Create or copy a GitHub token with read access to the repository.\n"
            . '2. Open ' . $this->adminUrl . "\n"
            . "3. Edit the deployment, paste the token, and save.\n"
            . "4. Press Check token to confirm GitHub accepts it.\n\n"
            . "Once saved, the token is locked with a key Deployward keeps itself, so a change of the "
            . "WordPress secret keys no longer affects it.\n";
    }
}
