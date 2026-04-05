<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        // Clean up test users created during tests
        $testUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'reset-test@example.com']);
        if ($testUser) {
            $this->em->remove($testUser);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function createTestUser(string $resetToken = null, \DateTimeImmutable $expiresAt = null): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('reset-test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles(['ROLE_PATIENT']);
        $user->setPassword($hasher->hashPassword($user, 'OldPassword1'));

        if ($resetToken !== null) {
            $user->setResetToken($resetToken);
            $user->setResetTokenExpiresAt($expiresAt ?? new \DateTimeImmutable('+1 hour'));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testInvalidTokenReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reset-password/this-token-does-not-exist-at-all');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testExpiredTokenShowsFlashErrorAndRedirects(): void
    {
        $expiredToken = bin2hex(random_bytes(16)) . '_expired';
        $this->createTestUser($expiredToken, new \DateTimeImmutable('-1 hour'));

        $client = static::createClient();
        $client->request('GET', '/reset-password/' . $expiredToken);

        // Should redirect to the request page
        $this->assertResponseRedirects('/reset-password');

        $client->followRedirect();

        $this->assertSelectorExists('.alert-danger');
        $this->assertSelectorTextContains('.alert-danger', 'expiré');
    }

    public function testValidTokenChangesPassword(): void
    {
        $validToken = bin2hex(random_bytes(16)) . '_valid';
        $user = $this->createTestUser($validToken, new \DateTimeImmutable('+1 hour'));
        $userId = $user->getId();

        $client = static::createClient();

        // GET: form is displayed
        $crawler = $client->request('GET', '/reset-password/' . $validToken);
        $this->assertResponseIsSuccessful();

        // POST: submit new password
        $form = $crawler->selectButton('Enregistrer le nouveau mot de passe')->form([
            'reset_password_form[plainPassword][first]' => 'NewPassword9',
            'reset_password_form[plainPassword][second]' => 'NewPassword9',
        ]);
        $client->submit($form);

        // Should redirect to login
        $this->assertResponseRedirects('/login');
        $client->followRedirect();

        $this->assertSelectorExists('.alert-success');

        // Verify: password was changed and token was cleared
        $this->em->clear();
        $updatedUser = $this->em->getRepository(User::class)->find($userId);

        $this->assertNotNull($updatedUser);
        $this->assertNull($updatedUser->getResetToken(), 'Token should be cleared after use');
        $this->assertNull($updatedUser->getResetTokenExpiresAt(), 'Token expiry should be cleared after use');

        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->assertTrue(
            $hasher->isPasswordValid($updatedUser, 'NewPassword9'),
            'Password should be updated to the new value'
        );
    }

    public function testValidTokenIsInvalidatedAfterUse(): void
    {
        $token = bin2hex(random_bytes(16)) . '_once';
        $this->createTestUser($token, new \DateTimeImmutable('+1 hour'));

        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password/' . $token);
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer le nouveau mot de passe')->form([
            'reset_password_form[plainPassword][first]' => 'FirstChange9',
            'reset_password_form[plainPassword][second]' => 'FirstChange9',
        ]);
        $client->submit($form);
        $this->assertResponseRedirects('/login');

        // Second attempt with the same token should 404
        $client->request('GET', '/reset-password/' . $token);
        $this->assertResponseStatusCodeSame(404);
    }
}
