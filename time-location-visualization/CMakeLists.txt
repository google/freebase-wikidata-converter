cmake_minimum_required (VERSION 2.6)
project (WikidataViz)

set(CMAKE_MODULE_PATH ${CMAKE_MODULE_PATH} "${CMAKE_SOURCE_DIR}/cmake/")

find_package(Boost 1.53.0 REQUIRED COMPONENTS iostreams regex)
find_package(png++ REQUIRED)

add_definitions(-std=c++11 -O3 -W -Wextra -Wcast-qual -Wcast-align -Wfloat-equal -Wshadow -Wpointer-arith -Wunreachable-code -Wchar-subscripts -Wcomment -Wformat -Werror-implicit-function-declaration -Wmain -Wmissing-braces -Wparentheses -Wsequence-point -Wreturn-type -Wswitch -Wuninitialized -Wreorder -Wundef -Wwrite-strings -Wsign-compare -pedantic -Wconversion -Wmissing-noreturn -Wold-style-cast -Weffc++ -Wall -Wunused -Wsign-conversion -Wunused -Wstrict-aliasing -Wstrict-overflow -Wconversion -Wdisabled-optimization)

add_executable(wikidata src/PngCreator.cpp src/wikidata.cpp)
target_link_libraries(wikidata ${Boost_LIBRARIES} ${png++_LIBRARIES})

add_executable(freebase src/PngCreator.cpp src/freebase.cpp)
target_link_libraries(freebase ${Boost_LIBRARIES} ${png++_LIBRARIES})