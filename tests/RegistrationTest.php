<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Inscription : protections anti-bot, vérification de l'email, non-divulgation des comptes existants.
 */
class RegistrationTest extends WebTestCase
{
    private const EMAIL_DOMAIN = '@registration-test.example';
    private const PASSWORD = 'Motdepasse123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        // Les compteurs de la limite par IP persistent entre les tests
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.email LIKE :domain')
            ->setParameter('domain', '%' . self::EMAIL_DOMAIN)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testHoneypotRejectsSilently(): void
    {
        $email = 'honeypot' . self::EMAIL_DOMAIN;

        $this->client->request('GET', '/register');
        sleep(3);
        $this->submitRegistration($email, ['registration_form[contactRef]' => 'http://spam.example']);

        $this->assertResponseRedirects('/register/check-email');
        $this->assertEmailCount(0);
        $this->assertNull($this->findUser($email));
    }

    public function testTooFastSubmissionRejectsSilently(): void
    {
        $email = 'toofast' . self::EMAIL_DOMAIN;

        $this->client->request('GET', '/register');
        $this->submitRegistration($email);

        $this->assertResponseRedirects('/register/check-email');
        $this->assertEmailCount(0);
        $this->assertNull($this->findUser($email));
    }

    public function testDirectPostWithoutDisplayingFormIsRejected(): void
    {
        $email = 'directpost' . self::EMAIL_DOMAIN;

        $this->client->request('POST', '/register', $this->registrationData($email));

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->findUser($email));
    }

    public function testRegistrationIsRateLimitedPerIp(): void
    {
        $this->client->request('GET', '/register');

        for ($i = 1; $i <= 5; ++$i) {
            $this->submitRegistration("flood{$i}" . self::EMAIL_DOMAIN);
            $this->assertResponseRedirects('/register/check-email');
        }

        $this->submitRegistration('flood6' . self::EMAIL_DOMAIN);
        $this->assertResponseStatusCodeSame(429);
    }

    public function testRegistrationRequiresEmailVerificationBeforeLogin(): void
    {
        $email = 'humain' . self::EMAIL_DOMAIN;

        // 1. Inscription : compte créé mais inactif, lien de confirmation envoyé
        $this->client->request('GET', '/register');
        sleep(3);
        $this->submitRegistration($email);

        $this->assertResponseRedirects('/register/check-email');
        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        $this->assertEmailAddressContains($mail, 'To', $email);
        $this->assertEmailTextBodyContains($mail, '/register/verify/');
        $this->assertEmailTextBodyNotContains($mail, self::PASSWORD);

        $user = $this->findUser($email);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());
        $token = $user->getVerificationToken();
        $this->assertNotNull($token);

        // 2. Mauvais mot de passe : ne révèle pas l'existence du compte non confirmé
        $this->login($email, 'MauvaisMotDePasse1');
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextNotContains('.alert-danger', 'confirmée');

        // 3. Bon mot de passe mais email non confirmé : connexion refusée
        $this->login($email, self::PASSWORD);
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'pas encore été confirmée');

        // 4. Clic sur le lien : compte activé, bienvenue + notification neuropsy
        $this->client->request('GET', '/register/verify/' . $token);
        $this->assertResponseRedirects('/login');
        $this->assertEmailCount(2);

        $this->em->clear();
        $user = $this->findUser($email);
        $this->assertTrue($user->isVerified());
        $this->assertNull($user->getVerificationToken());

        // 5. Le lien ne fonctionne qu'une fois
        $this->client->request('GET', '/register/verify/' . $token);
        $this->assertResponseRedirects('/login');
        $this->assertEmailCount(0);

        // 6. Connexion acceptée
        $this->login($email, self::PASSWORD);
        $this->assertResponseRedirects('/patient/dashboard');
    }

    public function testDuplicateEmailDoesNotRevealExistingAccount(): void
    {
        $email = 'existant' . self::EMAIL_DOMAIN;
        $this->createVerifiedUser($email);

        $this->client->request('GET', '/register');
        sleep(3);
        $this->submitRegistration($email);

        // Même réponse qu'une inscription réussie, le titulaire est prévenu par email
        $this->assertResponseRedirects('/register/check-email');
        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        $this->assertEmailAddressContains($mail, 'To', $email);
        $this->assertEmailHeaderSame($mail, 'Subject', 'Vous avez déjà un compte AutoEval');

        $count = $this->em->getRepository(User::class)->count(['email' => $email]);
        $this->assertSame(1, $count);
    }

    public function testInvalidFormStillShowsFieldErrors(): void
    {
        $this->client->request('GET', '/register');
        sleep(3);
        $this->submitRegistration('pas-un-email');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Veuillez saisir un email valide.');
    }

    private function submitRegistration(string $email, array $overrides = []): void
    {
        $this->client->request('POST', '/register', array_replace_recursive(
            $this->registrationData($email),
            $this->expandFieldNames($overrides),
        ));
    }

    /** @return array<string, mixed> */
    private function registrationData(string $email): array
    {
        return [
            'registration_form' => [
                'email' => $email,
                'firstName' => 'Test',
                'lastName' => 'Inscription',
                'consent' => '1',
                'plainPassword' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
                'contactRef' => '',
                '_token' => 'csrf-token',
            ],
        ];
    }

    /**
     * ['registration_form[contactRef]' => 'x'] → ['registration_form' => ['contactRef' => 'x']]
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function expandFieldNames(array $fields): array
    {
        $result = [];
        foreach ($fields as $name => $value) {
            parse_str(urlencode($name) . '=' . urlencode($value), $parsed);
            $result = array_replace_recursive($result, $parsed);
        }

        return $result;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => 'csrf-token',
        ]);
    }

    private function findUser(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function createVerifiedUser(string $email): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Déjà');
        $user->setLastName('Inscrit');
        $user->setRoles(['ROLE_PATIENT']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $this->em->persist($user);
        $this->em->flush();
    }
}
