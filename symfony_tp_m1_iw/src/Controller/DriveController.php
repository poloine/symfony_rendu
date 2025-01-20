<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DriveController extends AbstractController
{
    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem, private readonly LoggerInterface $logger)
    {
        $this->filesystem = $filesystem;
    }

    #[Route('/drive/{subPath?}', name: 'app_drive', requirements: ['subPath' => '.+'])]
    public function index(string $subPath = null): Response
    {
        $user = $this->getUser();
        $basePath = "filesystem/{{$user->getId()}}{$user->getName()}{$user->getFirstname()}";
        $subPath = $this->normalizePath($subPath);
        $path = $basePath . ($subPath ? '/' . $subPath : '');

        if (!$this->filesystem->exists($basePath)) {
            $this->createDriveDirectory($user->getId(), $user->getName(), $user->getFirstname());
        }

        if (!$this->filesystem->exists($path)) {
            throw $this->createNotFoundException("Le dossier spécifié n'existe pas.");
        }

        if (!str_starts_with(realpath($path), realpath($basePath))) {
            throw $this->createAccessDeniedException("Accès interdit au chemin spécifié.");
        }

        $files = $this->exploreRessources($path);

        $remaining_space = $user->getWorkspaceRemainingSize();

        return $this->render('drive/index.html.twig', [
            'files' => $files,
            'currentPath' => $subPath,
            'basePath' => $basePath,
            'remaining_space' => $remaining_space
        ]);
    }

    #[Route('/drive/add-folder', name: 'app_drive_add_folder_root', methods: ['POST'])]
    #[Route('/drive/add-folder/{subPath}', name: 'app_drive_add_folder', requirements: ['subPath' => '.+'], methods: ['POST'])]
    public function addFolder(Request $request, ?string $subPath = null): Response
    {
        $this->logger->info("--- Ajout d'un folder");
        $this->logger->info("--- Subpath: " . $subPath);

        $user = $this->getUser();
        $basePath = "filesystem/{$user->getId()}{$user->getName()}{$user->getFirstname()}";

        if (!$subPath) {
            $subPath = $request->request->get('subPath', '');
        }

        $subPath = $this->normalizePath($subPath);
        $path = $basePath . ($subPath ? '/' . $subPath : '');

        if (!str_starts_with(realpath($path), realpath($basePath))) {
            throw $this->createAccessDeniedException("Accès interdit au chemin spécifié.");
        }

        $folderName = $request->request->get('folder_name');

        if (empty($folderName)) {
            $this->addFlash('error', 'Le nom du dossier ne peut pas être vide.');
            return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
        }

        $newFolderPath = $path . '/' . $folderName;

        if ($this->filesystem->exists($newFolderPath)) {
            $this->addFlash('error', 'Un dossier avec ce nom existe déjà.');
            return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
        }

        try {
            $this->filesystem->mkdir($newFolderPath);
            $this->addFlash('success', 'Le dossier a été créé avec succès.');
        } catch (IOExceptionInterface $exception) {
            $this->addFlash('error', 'Erreur lors de la création du dossier : ' . $exception->getMessage());
        }

        return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
    }

    #[Route('/drive/upload-file', name: 'app_drive_upload_file', methods: ['POST'])]
    #[Route('/drive/upload-file/{subPath}', name: 'app_drive_upload_file_path', requirements: ['subPath' => '.+'], methods: ['POST'])]
    public function uploadFile(Request $request, ?string $subPath = null): Response
    {
        $this->logger->info("--- Upload d'un fichier");
        $user = $this->getUser();
        $basePath = "filesystem/{$user->getId()}{$user->getName()}{$user->getFirstname()}";

        if (!$subPath) {
            $subPath = $request->request->get('subPath', '');
        }

        $subPath = $this->normalizePath($subPath);
        $path = $basePath . ($subPath ? '/' . $subPath : '');

        if (!str_starts_with(realpath($path), realpath($basePath))) {
            throw $this->createAccessDeniedException("Accès interdit au chemin spécifié.");
        }

        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier sélectionné pour l\'upload.');
            return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
        }

        $fileSize = $file->getSize();
        $remainingSize = (int) $user->getWorkspaceRemainingSize();

        if ($fileSize > $remainingSize) {
            $this->addFlash('error', 'Le fichier dépasse la taille restante disponible.');
            return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
        }

        try {
            $filename = $file->getClientOriginalName();
            $file->move($path, $filename);

            // Mettre à jour l'espace restant
            $user->setWorkspaceRemainingSize($remainingSize - $fileSize);
            $this->getDoctrine()->getManager()->flush();

            $this->addFlash('success', 'Le fichier a été uploadé avec succès.');
        } catch (\Exception $exception) {
            $this->addFlash('error', 'Erreur lors de l\'upload : ' . $exception->getMessage());
        }

        return $this->redirectToRoute('app_drive', ['subPath' => $subPath]);
    }

    private function normalizePath(?string $path): string
    {
        return trim(preg_replace('#/+#', '/', $path ?? ''), '/');
    }

    public function createDriveDirectory($id, $name, $firstname): void {
        try {
            $this->filesystem->mkdir("filesystem/{{$id}}{$name}{$firstname}");
        } catch (IOExceptionInterface $exception) {
            $this->logger->error("-> exception: ".$exception->getMessage());
        }

    }

    public function exploreRessources(string $path): array
    {
        $finder = new Finder();
        $finder->depth('== 0')->in($path);
        if (!$finder->hasResults()) {
            return [];
        }

        $array = iterator_to_array($finder);
        usort($array, function ($a, $b) {
            if ($a->isDir() && !$b->isDir()) {
                return -1;
            } elseif (!$a->isDir() && $b->isDir()) {
                return 1;
            }
            return 0;
        });
        return $array;
    }

}
