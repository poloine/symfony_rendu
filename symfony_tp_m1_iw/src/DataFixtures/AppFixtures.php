<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setName("Nom")
            ->setFirstname("Prénom")
            ->setEmail("test@mail.com")
            ->setPassword($this->hasher->hashPassword($user, 'password'))
            ->setWorkspaceMaxSize("2GB")
            ->setRoles(['ROLE_ADMIN'])
            ->setWorkspaceRemainingSize(0);
        $manager->persist($user);

        $faker = \Faker\Factory::create();

        for ($i = 0; $i < 10; $i++) {
            $user = new User();
            $user->setName($faker->lastName())
                ->setFirstname($faker->firstName)
                ->setEmail($faker->email)
                ->setPassword($this->hasher->hashPassword($user, 'test'))
                ->setRoles(['ROLE_USER'])
                ->setWorkspaceMaxSize($faker->randomElement(["1GB", "2GB", "3GB", "4GB", "5GB"]))
                ->setWorkspaceRemainingSize($faker->randomElement([0, 100, 200, 300, 400, 500]));
            $manager->persist($user);
        }

        $manager->flush();
    }
}
