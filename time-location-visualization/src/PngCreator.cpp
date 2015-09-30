#include "PngCreator.h"

#include <png++/png.hpp>

#include <iostream>
#include <unordered_set>
#include <unordered_map>
#include <cmath>

void PngCreator::createPng(
        const std::string& fileNamePrefix,
        const std::unordered_set<size_t>& ids,
        const PropagatedValue<size_t, long long int, Min<long long int>>& time,
        const PropagatedValue<size_t, float, Average<float>>& longitudes) {

    const size_t X_SIZE = 360*10+1;
    const size_t Y_SIZE = 2001;
    std::vector<std::vector<int>> image_data(X_SIZE, std::vector<int>(Y_SIZE, 0)); //x => y => number of items
    const long long int TIME_START = -5000*12*31;
    const long long int TIME_END = 2015*12*31 + 1;

    size_t count = 0;
    int total_count = 0;
    for(const size_t id : ids) {
        count++;
        if((count % 100000) == 0) {
            std::cout << "." << std::flush;
        }

        try {
            long long int timeValue = time.getValue(id);
            float longitude = longitudes.getValue(id);
            if(TIME_START < timeValue && timeValue < TIME_END && -180 < longitude && longitude < 180) {
                total_count++;
                size_t x = (longitude + 180) * 10;
                size_t y = (pow(timeValue - TIME_START, 8) * ((double) Y_SIZE)) / (pow(TIME_END - TIME_START, 8));
                image_data[x][y]++;
            }
        } catch(std::out_of_range&) {
        }
    }

    //paint the image
    png::image<png::rgb_pixel> image(X_SIZE, Y_SIZE);

    int pixels_count = 0;
    for(size_t x = 0; x < X_SIZE; x++) {
        for(size_t y = 0; y < Y_SIZE; y++) {
            int item_count = image_data[x][y];
            if(item_count > 0) {
                pixels_count++;
                image[y][x] = png::rgb_pixel(255, 255, std::min(item_count * 10, 255));
            }
        }
    }

    std::cout << std::endl << "there are " << total_count << " located items for " << ids.size() << " items with statements with " << pixels_count << " used pixels" << std::endl;
    std::cout << "saving" << std::endl;
    image.write(fileNamePrefix + ".png");

    //add a grid
    for(int longitude = -170; longitude <= 170; longitude += 10) {
        for(size_t y = 0; y < Y_SIZE; y++) {
            image[y][(longitude + 180) * 10] = png::rgb_pixel(0, 0, 255);
        }
    }
    for(size_t year = 0; year <= 2000; year += 100) {
        size_t y = (pow(year*12*31 - TIME_START, 8) * ((double) Y_SIZE)) / (pow(TIME_END - TIME_START, 8));
        for(size_t x = 0; x < X_SIZE; x++) {
            image[y][x] = png::rgb_pixel(0, 0, 255);
        }
    }
    image.write(fileNamePrefix + "-grid.png");
}
