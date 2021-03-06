<?php

namespace Tests\AppBundle\Consumer;

use AppBundle\Consumer\SyncStarredRepos;
use AppBundle\Entity\Repo;
use AppBundle\Entity\User;
use Github\Client as GithubClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Swarrot\Broker\Message;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SyncStarredReposTest extends WebTestCase
{
    public function testProcessNoUser()
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient = $this->getMockBuilder('Github\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient->expects($this->never())
            ->method('authenticate');

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            new NullLogger()
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);
    }

    public function testProcessSuccessfulMessage()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($user));

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666, 777]);

        $starRepository->expects($this->once())
            ->method('removeFromUser')
            ->with([1 => 777], 123)
            ->willReturn(true);

        $repo = new Repo();
        $repo->setId(666);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt((new \DateTime())->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(666)
            ->willReturn($repo);

        $responses = new MockHandler([
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // first /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // third /user/starred will return empty response which means, we reached the last page
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('    sync 1 starred repos', $records[2]['message']);
        $this->assertSame('Removed stars: 1', $records[3]['message']);
        $this->assertSame('Synced repos: 1', $records[4]['message']);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage booboo
     */
    public function testProcessUnexpectedError()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($user));

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->once())
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([666]);

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(666)
            ->will($this->throwException(new \Exception('booboo')));

        $responses = new MockHandler([
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // first /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // second /user/starred will return empty response which means, we reached the last page
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $githubClient = $this->getMockClient($responses);

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            new NullLogger()
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);
    }

    /**
     * Everything will goes fine (like testProcessSuccessfulMessage) and we won't remove old stars (no change detected in starred repos).
     */
    public function testProcessSuccessfulMessageNoStarToRemove()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(false); // simulate a closing manager

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);
        $doctrine->expects($this->once())
            ->method('resetManager')
            ->willReturn($em);

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($user));

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->exactly(2))
            ->method('findAllByUser')
            ->with(123)
            ->willReturn([123]);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('j0k3r/banditore');
        $repo->setUpdatedAt((new \DateTime())->setTimestamp(time() - 3600 * 72));

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn($repo);

        $responses = new MockHandler([
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // first /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 123,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // second /user/starred will return empty response which means, we reached the last page
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('    sync 1 starred repos', $records[2]['message']);
        $this->assertSame('Synced repos: 1', $records[3]['message']);
    }

    public function testProcessWithBadClient()
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->never())
            ->method('find');

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->never())
            ->method('find');

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            false, // simulate a bad client
            $logger
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('No client provided', $records[0]['message']);
    }

    public function testProcessWithRateLimitReached()
    {
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->never())
            ->method('isOpen');

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->never())
            ->method('getManager');

        $user = new User();
        $user->setId(123);
        $user->setUsername('bob');
        $user->setName('Bobby');

        $userRepository = $this->getMockBuilder('AppBundle\Repository\UserRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $userRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($user));

        $starRepository = $this->getMockBuilder('AppBundle\Repository\StarRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $starRepository->expects($this->never())
            ->method('findAllByUser');

        $starRepository->expects($this->never())
            ->method('removeFromUser');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->never())
            ->method('find');

        $responses = new MockHandler([
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 0]]])),
        ]);

        $githubClient = $this->getMockClient($responses);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncStarredRepos(
            $doctrine,
            $userRepository,
            $starRepository,
            $repoRepository,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_starred_repos message', $records[0]['message']);
        $this->assertSame('[0] Check <info>bob</info> … ', $records[1]['message']);
        $this->assertSame('RateLimit reached, stopping.', $records[2]['message']);
    }

    public function testFunctionalConsumer()
    {
        $responses = new MockHandler([
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // first /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'banditore',
                'homepage' => 'http://banditore.io',
                'language' => 'PHP',
                'name' => 'banditore',
                'full_name' => 'j0k3r/banditore',
                'id' => 777,
                'owner' => [
                    'avatar_url' => 'http://avatar.api/banditore.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // second /user/starred
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'description' => 'This is a test repo',
                'homepage' => 'http://test.io',
                'language' => 'Ruby',
                'name' => 'test',
                'full_name' => 'test/test',
                'id' => 666,
                'owner' => [
                    'avatar_url' => 'http://0.0.0.0/test.jpg',
                ],
            ]])),
            // /rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 8]]])),
            // third /user/starred will return empty response which means, we reached the last page
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
        ]);

        $githubClient = $this->getMockClient($responses);

        $client = static::createClient();
        $container = $client->getContainer();

        // override factory to avoid real call to Github
        $container->set('banditore.client.github', $githubClient);

        $processor = $container->get('banditore.consumer.sync_starred_repos');

        // before import
        $stars = $container->get('banditore.repository.star')->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(555, $stars[0], 'User 123 has "symfony/symfony" starred repo');
        $this->assertSame(666, $stars[1], 'User 123 has "test/test" starred repo');

        $processor->process(new Message(json_encode(['user_id' => 123])), []);

        $repo = $container->get('banditore.repository.repo')->find(777);
        $this->assertNotNull($repo, 'Imported repo with id 777 exists');
        $this->assertSame('j0k3r/banditore', $repo->getFullName(), 'Imported repo with id 777 exists');

        // validate that `test/test` association got removed
        $stars = $container->get('banditore.repository.star')->findAllByUser(123);
        $this->assertCount(2, $stars, 'User 123 has 2 starred repos');
        $this->assertSame(666, $stars[0], 'User 123 has "test/test" starred repo');
        $this->assertSame(777, $stars[1], 'User 123 has "j0k3r/banditore" starred repo');
    }

    private function getMockClient($responses)
    {
        $clientHandler = HandlerStack::create($responses);

        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        return $githubClient;
    }
}
