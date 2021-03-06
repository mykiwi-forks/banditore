<?php

namespace Tests\AppBundle\Consumer;

use AppBundle\Consumer\SyncVersions;
use AppBundle\Entity\Repo;
use AppBundle\Entity\Version;
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

class SyncVersionsTest extends WebTestCase
{
    public function testProcessNoRepo()
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->willReturn(null);

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient = $this->getMockBuilder('Github\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $githubClient->expects($this->never())
            ->method('authenticate');

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            new NullLogger()
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);
    }

    public function getWorkingResponses()
    {
        return new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'name' => '2.0.1',
                'zipball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/zipball/2.0.1',
                'tarball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/tarball/2.0.1',
                'commit' => [
                    'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
                ],
            ]])),
            // git/refs/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                [
                    'ref' => 'refs/tags/1.0.0',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.0',
                    'object' => [
                        'sha' => '04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                    ],
                ],
                [
                    'ref' => 'refs/tags/1.0.1',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.1',
                    'object' => [
                        'sha' => '4845571072d49c2794b165482420b66c206a942a',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/4845571072d49c2794b165482420b66c206a942a',
                    ],
                ],
                [
                    'ref' => 'refs/tags/1.0.2',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.2',
                    'object' => [
                        'sha' => '694b8cc3983f52209029605300910507bec700b4',
                        'type' => 'tag',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/tags/694b8cc3983f52209029605300910507bec700b4',
                    ],
                ],
                [
                    'ref' => 'refs/tags/2.0.1',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/2.0.1',
                    'object' => [
                        'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
                    ],
                ],
            ])),
            // TAG 1.0.1
            // repos/release with tag 1.0.1 (which is not a release)
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Not Found',
                'documentation_url' => 'https://developer.github.com/v3',
            ])),
            // retrieve tag information from the commit (since the release does not exist)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sha' => '4845571072d49c2794b165482420b66c206a942a',
                'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/4845571072d49c2794b165482420b66c206a942a',
                'html_url' => 'https://github.com/snc/SncRedisBundle/commit/4845571072d49c2794b165482420b66c206a942a',
                'author' => [
                    'name' => 'Daniele Alessandri',
                    'email' => 'suppakilla@gmail.com',
                    'date' => '2011-10-15T07:49:04Z',
                ],
                'committer' => [
                    'name' => 'Daniele Alessandri',
                    'email' => 'suppakilla@gmail.com',
                    'date' => '2011-10-15T07:49:21Z',
                ],
                'tree' => [
                    'sha' => '0f570c5083aa017b7cb5a4b83869ed5054c17764',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/trees/0f570c5083aa017b7cb5a4b83869ed5054c17764',
                ],
                'message' => 'Use the correct package type for composer.',
                'parents' => [[
                    'sha' => '40f7ee543e217aa3a1eadbc952df56b548071d20',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/40f7ee543e217aa3a1eadbc952df56b548071d20',
                    'html_url' => 'https://github.com/snc/SncRedisBundle/commit/40f7ee543e217aa3a1eadbc952df56b548071d20',
                ]],
            ])),
            // markdown
            new Response(200, ['Content-Type' => 'text/html'], '<p>Use the correct package type for composer.</p>'),
            // TAG 1.0.2
            // repos/release with tag 1.0.2 (which is not a release)
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'Not Found',
                'documentation_url' => 'https://developer.github.com/v3',
            ])),
            // retrieve tag information from the tag (since the release does not exist)
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sha' => '694b8cc3983f52209029605300910507bec700b4',
                'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/tags/694b8cc3983f52209029605300910507bec700b4',
                'tagger' => [
                    'name' => 'Erwin Mombay',
                    'email' => 'erwinm@google.com',
                    'date' => '2012-10-18T17:23:37Z',
                ],
                'object' => [
                    'sha' => '694b8cc3983f52209029605300910507bec700b5',
                    'type' => 'commit',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/694b8cc3983f52209029605300910507bec700b5',
                ],
                'tag' => '1.0.2',
                'message' => "weekly release\n-----BEGIN PGP SIGNATURE-----\nVersion: GnuPG v2\n\niF4EABEIAAYFAliw58IACgkQ64qmmlZsB5VNFwD+L1M86cO76oohqSy4TCbubPAL\n6341glOKJpfkwyjQnUkBAPCTZSBbe8CFHLxLUvypIiQSMn+AIkPfvzvSEahA40Vz\n=SaF+\n-----END PGP SIGNATURE-----\n",
            ])),
            // markdown
            new Response(200, ['Content-Type' => 'text/html'], '<p>weekly release</p>'),
            // TAG 2.0.1
            // now tag 2.0.1 which is a release
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'tag_name' => '2.0.1',
                'name' => '2.0.1',
                'prerelease' => false,
                'published_at' => '2017-02-19T13:27:32Z',
                'body' => 'yay',
            ])),
            // markdown
            new Response(200, ['Content-Type' => 'text/html'], '<p>yay</p>'),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);
    }

    public function testProcessSuccessfulMessage()
    {
        $uow = $this->getMockBuilder('Doctrine\ORM\UnitOfWork')
            ->disableOriginalConstructor()
            ->getMock();
        $uow->expects($this->exactly(3))
            ->method('getScheduledEntityInsertions')
            ->willReturn([]);

        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(false); // simulate a closing manager
        $em->expects($this->exactly(3))
            ->method('getUnitOfWork')
            ->willReturn($uow);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);
        $doctrine->expects($this->once())
            ->method('resetManager')
            ->willReturn($em);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $versionRepository->expects($this->exactly(4))
            ->method('findExistingOne')
            ->will($this->returnCallback(function ($tagName, $repoId) use ($repo) {
                // first version will exist, next one won't
                if ($tagName === '1.0.0') {
                    return new Version($repo);
                }
            }));

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->once())
            ->method('pingHub')
            ->with([123]);

        $clientHandler = HandlerStack::create($this->getWorkingResponses());
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertSame('[10] <comment>3</comment> new versions for <info>bob/wow</info>', $records[2]['message']);
    }

    /**
     * The call to repo/tags will return a bad response.
     */
    public function testProcessRepoTagFailed()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags generate a bad request
            new Response(400, ['Content-Type' => 'application/json']),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertContains('(repo/tags) <error>', $records[2]['message']);
    }

    /**
     * Not enough calls remaining.
     */
    public function testProcessCallsRemaingLow()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 0]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[0] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertContains('RateLimit reached, stopping.', $records[2]['message']);
    }

    /**
     * The call to markdown will return a bad response.
     */
    public function testProcessMarkdownFailed()
    {
        $uow = $this->getMockBuilder('Doctrine\ORM\UnitOfWork')
            ->disableOriginalConstructor()
            ->getMock();
        $uow->expects($this->once())
            ->method('getScheduledEntityInsertions')
            ->willReturn([]);

        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $em->expects($this->once())
            ->method('isOpen')
            ->willReturn(true);
        $em->expects($this->once())
            ->method('getUnitOfWork')
            ->willReturn($uow);

        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->once())
            ->method('getManager')
            ->willReturn($em);

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $versionRepository->expects($this->once())
            ->method('findExistingOne')
            ->willReturn(null);

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'name' => '2.0.1',
                'zipball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/zipball/2.0.1',
                'tarball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/tarball/2.0.1',
                'commit' => [
                    'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
                ],
            ]])),
            // git/refs/tags generate a bad request
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                [
                    'ref' => 'refs/tags/1.0.0',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.0',
                    'object' => [
                        'sha' => '04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                    ],
                ],
            ])),
            // now tag 1.0.0 which is a release
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'tag_name' => '1.0.0',
                'name' => '1.0.0',
                'prerelease' => false,
                'published_at' => '2017-02-19T13:27:32Z',
                'body' => 'yay',
            ])),
            // markdown failed
            new Response(400, ['Content-Type' => 'text/html'], 'booboo'),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertContains('<error>Failed to parse markdown', $records[2]['message']);
    }

    /**
     * No tag found for that repo.
     */
    public function testProcessNoTagFound()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([])),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertSame('[10] <comment>0</comment> new versions for <info>bob/wow</info>', $records[2]['message']);
    }

    /**
     * Generate an unexpected error (like from MySQL).
     *
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $versionRepository->expects($this->once())
            ->method('findExistingOne')
            ->will($this->throwException(new \Exception('booboo')));

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'name' => '2.0.1',
                'zipball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/zipball/2.0.1',
                'tarball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/tarball/2.0.1',
                'commit' => [
                    'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
                ],
            ]])),
            // git/refs/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                [
                    'ref' => 'refs/tags/1.0.0',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/refs/tags/1.0.0',
                    'object' => [
                        'sha' => '04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/snc/SncRedisBundle/git/commits/04b99722e0c25bfc45926cd3a1081c04a8e950ed',
                    ],
                ],
            ])),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            new NullLogger()
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);
    }

    /**
     * The call to git/refs/tags will return a bad response.
     */
    public function testProcessGitRefTagFailed()
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

        $repo = new Repo();
        $repo->setId(123);
        $repo->setFullName('bob/wow');
        $repo->setName('wow');

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->once())
            ->method('find')
            ->with(123)
            ->will($this->returnValue($repo));

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'name' => '2.0.1',
                'zipball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/zipball/2.0.1',
                'tarball_url' => 'https://api.github.com/repos/snc/SncRedisBundle/tarball/2.0.1',
                'commit' => [
                    'sha' => '02c808d157c79ac32777e19f3ec31af24a32d2df',
                    'url' => 'https://api.github.com/repos/snc/SncRedisBundle/commits/02c808d157c79ac32777e19f3ec31af24a32d2df',
                ],
            ]])),
            // git/refs/tags generate a bad request
            new Response(400, ['Content-Type' => 'application/json']),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            $githubClient,
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('Consume banditore.sync_versions message', $records[0]['message']);
        $this->assertSame('[10] Check <info>bob/wow</info> … ', $records[1]['message']);
        $this->assertContains('(git/refs/tags) <error>', $records[2]['message']);
    }

    public function testProcessWithBadClient()
    {
        $doctrine = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository = $this->getMockBuilder('AppBundle\Repository\RepoRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $repoRepository->expects($this->never())
            ->method('find');

        $versionRepository = $this->getMockBuilder('AppBundle\Repository\VersionRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub = $this->getMockBuilder('AppBundle\PubSubHubbub\Publisher')
            ->disableOriginalConstructor()
            ->getMock();

        $pubsubhubbub->expects($this->never())
            ->method('pingHub');

        $logger = new Logger('foo');
        $logHandler = new TestHandler();
        $logger->pushHandler($logHandler);

        $processor = new SyncVersions(
            $doctrine,
            $repoRepository,
            $versionRepository,
            $pubsubhubbub,
            false, // simulate a bad client
            $logger
        );

        $processor->process(new Message(json_encode(['repo_id' => 123])), []);

        $records = $logHandler->getRecords();

        $this->assertSame('No client provided', $records[0]['message']);
    }

    /**
     * Using mocks only for request.
     */
    public function testFunctionalConsumer()
    {
        $clientHandler = HandlerStack::create($this->getWorkingResponses());
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $client = static::createClient();
        $container = $client->getContainer();

        // override factory to avoid real call to Github
        $container->set('banditore.client.github', $githubClient);

        $guzzleClientPub = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();
        $guzzleClientPub->expects($this->once())
            ->method('__call') // post
            ->willReturn(new Response(204));

        $container->set('banditore.client.guzzle', $guzzleClientPub);

        $processor = $container->get('banditore.consumer.sync_versions');

        $versions = $container->get('banditore.repository.version')->findBy(['repo' => 666]);
        $this->assertCount(1, $versions, 'Repo 666 has 1 version');
        $this->assertSame('1.0.0', $versions[0]->getTagName(), 'Repo 666 has 1 version, which is 1.0.0');

        $processor->process(new Message(json_encode(['repo_id' => 666])), []);

        $versions = $container->get('banditore.repository.version')->findBy(['repo' => 666]);
        $this->assertCount(4, $versions, 'Repo 666 has now 4 versions');
        $this->assertSame('1.0.0', $versions[0]->getTagName(), 'Repo 666 has 4 version. First one is 1.0.0');
        $this->assertSame('1.0.1', $versions[1]->getTagName(), 'Repo 666 has 4 version. Second one is 1.0.1');
        $this->assertSame('1.0.2', $versions[2]->getTagName(), 'Repo 666 has 4 version. Third one is 1.0.2');
        $this->assertSame('<p>weekly release</p>', $versions[2]->getBody(), 'Version 1.0.2 does NOT have a PGP signature');
        $this->assertSame('2.0.1', $versions[3]->getTagName(), 'Repo 666 has 4 version. Fourth one is 2.0.1');
    }

    public function testFunctionalConsumerWithTagCaseInsensitive()
    {
        $responses = new MockHandler([
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
            // repo/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([[
                'name' => 'v2.11.0',
                'zipball_url' => 'https://api.github.com/repos/mozilla/metrics-graphics/zipball/v2.11.0',
                'tarball_url' => 'https://api.github.com/repos/mozilla/metrics-graphics/tarball/v2.11.0',
            ]])),
            // git/refs/tags
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                [
                    'ref' => 'refs/tags/V1.1.0',
                    'url' => 'https://api.github.com/repos/mozilla/metrics-graphics/git/refs/tags/V1.1.0',
                    'object' => [
                        'sha' => '6402716c3165eb90cdace5729a18706ea2921187',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/mozilla/metrics-graphics/git/commits/6402716c3165eb90cdace5729a18706ea2921187',
                    ],
                ],
                [
                    'ref' => 'refs/tags/v1.1.0',
                    'url' => 'https://api.github.com/repos/mozilla/metrics-graphics/git/refs/tags/v1.1.0',
                    'object' => [
                        'sha' => '15a4703db568342043f156b5635d10b17ebe98cb',
                        'type' => 'commit',
                        'url' => 'https://api.github.com/repos/mozilla/metrics-graphics/git/commits/15a4703db568342043f156b5635d10b17ebe98cb',
                    ],
                ],
            ])),
            // TAG V1.1.0
            // now tag V1.1.0 which is a release
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'tag_name' => 'V1.1.0',
                'name' => 'V1.1.0',
                'prerelease' => false,
                'published_at' => '2014-12-01T18:28:39Z',
                'body' => 'This is the first release after our major push.',
            ])),
            // markdown
            new Response(200, ['Content-Type' => 'text/html'], '<p>This is the first release after our major push.</p>'),
            // rate_limit
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['resources' => ['core' => ['remaining' => 10]]])),
        ]);

        $clientHandler = HandlerStack::create($responses);
        $guzzleClient = new Client([
            'handler' => $clientHandler,
        ]);

        $httpClient = new Guzzle6Client($guzzleClient);
        $httpBuilder = new Builder($httpClient);
        $githubClient = new GithubClient($httpBuilder);

        $client = static::createClient();
        $container = $client->getContainer();

        // override factory to avoid real call to Github
        $container->set('banditore.client.github', $githubClient);

        $guzzleClientPub = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();
        $guzzleClientPub->expects($this->once())
            ->method('__call') // post
            ->willReturn(new Response(204));

        $container->set('banditore.client.guzzle', $guzzleClientPub);

        $processor = $container->get('banditore.consumer.sync_versions');

        $versions = $container->get('banditore.repository.version')->findBy(['repo' => 555]);
        $this->assertCount(1, $versions, 'Repo 555 has 1 version');
        $this->assertSame('1.0.21', $versions[0]->getTagName(), 'Repo 555 has 1 version, which is 1.0.21');

        $processor->process(new Message(json_encode(['repo_id' => 555])), []);

        $versions = $container->get('banditore.repository.version')->findBy(['repo' => 555]);
        $this->assertCount(2, $versions, 'Repo 555 has now 2 versions');
        $this->assertSame('1.0.21', $versions[0]->getTagName(), 'Repo 555 has 2 version. First one is 1.0.21');
        $this->assertSame('V1.1.0', $versions[1]->getTagName(), 'Repo 555 has 2 version. Second one is V1.1.0');
        $this->assertSame('<p>This is the first release after our major push.</p>', $versions[1]->getBody(), 'Version V1.1.0 body is ok');
    }
}
