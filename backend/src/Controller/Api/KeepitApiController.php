<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use App\Repository\ImageRepository;
use App\Entity\Image;

use App\Repository\UserRepository;
use App\Entity\User;

use App\Repository\TagRepository;
use App\Entity\Tag;

use App\Repository\KeepitRepository;
use App\Entity\Keepit;

use App\Utils\LocalFiles;


class KeepitApiController extends AbstractController
{
    /**
     *
     * @Route("/keepit/add", methods={"POST"})
     */
    public function preload(
        Request $request, 
        ImageRepository $imageRepository, 
        TagRepository $tagRepository, 
        UserRepository $userRepository, 
        KeepitRepository $keepitRepository
        ) {

        $requestContent = json_decode($request->getContent(), true); 
        $tags = $requestContent['requestTags'];
        $imageIds = $requestContent['imageIds'];
        
        $user = $userRepository->login($requestContent['email'], $requestContent['password']);
        if ($user === null) {
            return new JsonResponse(
                ["error" => "User not found."],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $newKeepit = new Keepit();

        $newKeepit->setUser($user);
        $newKeepit->setRating($requestContent['rated']);

        if($tags){
            foreach($tags as $key => $value){
                $newTag = new Tag();
                $newTag->setValue($value['value']);
                $newTag->setIsCustom($value['isCustom']);
                $newTag->setUser($user);
                foreach($imageIds as $key => $value){
                    $newTag->setImage($imageRepository->findById($imageIds[$key]));
                }
                $newTag = $tagRepository->save($newTag);
                $newKeepit->addTag($newTag);
            }
        }

        $newAddedKeepit = $keepitRepository->save($newKeepit);
        foreach($imageIds as $key => $value){
            $image = $imageRepository->findById($imageIds[$key]);
            $image->setKeepit($newAddedKeepit);
            $image->setSubmitted(true);
            $imageRepository->save($image);
        }

        $response = new JsonResponse($newAddedKeepit);
        return $response;
    }

    /**
     *
     * @Route("/keepit/getall", methods={"POST"})
     */
    public function getall(
        Request $request, 
        ImageRepository $imageRepository, 
        TagRepository $tagRepository, 
        UserRepository $userRepository, 
        KeepitRepository $keepitRepository
        ) {

        $requestContent = json_decode($request->getContent(), true); 

        $user = $userRepository->login($requestContent['email'], $requestContent['password']);
        if ($user === null) {
            return new JsonResponse(
                ["error" => "User not found."],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $keepits = $keepitRepository->findby(["user" => $user->getId()]);
        
        $responseArr = array();
        foreach($keepits as $key => $keepit){
            $tags = $keepit->getTags();
            $images = $keepit->getImage();
            $responseArr[$key]['rated'] = $keepit->getRating();

            $responseArr[$key]['id'] = $keepit->id;
            foreach($images as $imageKey => $image){
                $responseArr[$key]['images'][$imageKey] = $image->getPath();
            }
            foreach($tags as $tagKey => $tag){
                $responseArr[$key]['tags'][$tagKey] = array( 'value' => $tag->getValue(), 'isCustom' => $tag->getIsCustom() );
            }
            if(count($tags) === 0){
                $responseArr[$key]['tags'][] = array( 'value' => 'Not Tagged', 'isCustom' => false);
            }
        }
        $response = new JsonResponse($responseArr);
        return $response;
    }

     /**
     *
     * @Route("/keepit/{id}", methods={"DELETE"})
     */
    public function removeKeepit(
        $id,
        Request $request, 
        ImageRepository $imageRepository, 
        TagRepository $tagRepository, 
        UserRepository $userRepository, 
        KeepitRepository $keepitRepository,
        LocalFiles $localFiles
        ) {

        $keepit = $keepitRepository->findOneBy(['id' => $id]);

        if ($keepit === null) {
            return new JsonResponse(
                ["error" => "Keepit not found."],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $imageIdsToDelete = [];
        $images = $keepit->getImage();
        foreach($images as $image){
            $path = $image->getPath();
            $imageId = $image->getId();
            $imageIdsToDelete[] = $imageId;
            // var_dump($imageId);
            // var_dump($path);
            $keepit->removeImage($image);
            $localFiles->delete($path);
        }
        
        $tags = $keepit->getTags();
        foreach($tags as $tag){
            $keepit->removeTag($tag);
            $tagRepository->delete($tag);
        }

        $keepitRepository->delete($keepit);

        // var_dump($imageIdsToDelete);

        foreach($imageIdsToDelete as $imageIdToDelete){
            $imageToDelete = $imageRepository->findOneBy(['id' => $imageIdToDelete]);
            $imageRepository->delete($imageToDelete);
        }

        return new JsonResponse(
            ["Status" => "Keepit deleted"],
            JsonResponse::HTTP_OK
        );

    }

    /**
     *
     * @Route("/keepit/preload", methods={"POST"})
     */
    public function apiTags(
        Request $request, 
        SerializerInterface $serializer, 
        ImageRepository $imageRepository, 
        UserRepository $userRepository, 
        LocalFiles $localFiles, 
        VisionApi $visionApiRepository
        ){

        $requestContent = json_decode($request->getContent(), true);
        $images = $requestContent['files'];
        $imagelabels = [];
        foreach($images as $image){

            //$path = $localFiles->save($image['data_url']); 
            $path = $localFiles->save($image); 

            $labels = $visionApiRepository->getLabels($path);
            foreach($labels as $label){
                $imagelabels[] = $label;
            }

            $user = $userRepository->login($requestContent['email'], $requestContent['password']);

            if ($user === null) {
                return new JsonResponse(
                    ["error" => "User not found."],
                    JsonResponse::HTTP_BAD_REQUEST
                );
            }

            $newImage = new Image();
            $newImage->setPath($path);
            $newImage->setSubmitted(false);
            $newImage->setUser($user);
            $savedImage = $imageRepository->save($newImage);
            $imageIds[] = $savedImage->id;
        }
     
        $collectedResponse['ids'] = $imageIds;
        $collectedResponse['labels'] = $imagelabels;

        $response = new JsonResponse($collectedResponse);
        return $response;
    }

    
}

