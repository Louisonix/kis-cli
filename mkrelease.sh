#!/bin/sh 

REL_VERSION=1.0

script_path="$0"

# Resolve the script path if it's a symlink
# This ensures we get the actual path to the script
while [ -h "$script_path" ]; do
    script_dir="$( cd -P "$( dirname "$script_path" )" >/dev/null 2>&1 && pwd )"
    script_path="$( readlink "$script_path" )"
    # If readlink returns a relative path, resolve it against the current directory
    if [[ "$script_path" != /* ]]; then
        script_path="$script_dir/$script_path"
    fi
done

# Get the directory containing the script
script_dir="$( cd -P "$( dirname "$script_path" )" >/dev/null 2>&1 && pwd )"
release_dir=$script_dir/releases/kis-cli-$REL_VERSION
tmp_release_dir="/tmp/kis-cli-$REL_VERSION/"
release_tar_file="kis-cli-$REL_VERSION.tar"
release_tar_compressed="$release_tar_file.bz2"

mkdir $tmp_release_dir
mkdir $release_dir -p

mkdir $tmp_release_dir/snapshots
mkdir $tmp_release_dir/var

cp $script_dir/bin $tmp_release_dir -ra
cp $script_dir/config $tmp_release_dir -ra
cp $script_dir/doc $tmp_release_dir -ra
cp $script_dir/src $tmp_release_dir -ra
cp $script_dir/resources $tmp_release_dir -ra
cp $script_dir/translations $tmp_release_dir -ra
cp $script_dir/composer.json $tmp_release_dir 
cp $script_dir/COPYING $tmp_release_dir 
cp $script_dir/cronjob.sample.sh $tmp_release_dir 
cp $script_dir/examples.txt $tmp_release_dir 
cp $script_dir/lib-replay-README.md $tmp_release_dir 
cp $script_dir/LICENSE $tmp_release_dir 
cp $script_dir/mkrelease.sh $tmp_release_dir 
cp $script_dir/NOTES.txt $tmp_release_dir 
cp $script_dir/phpstan.dist.neon $tmp_release_dir 
cp $script_dir/README.md $tmp_release_dir

rm $tmp_release_dir/resources/host-europe/config.json

cd /tmp
ls $tmp_release_dir -alht

tar -cvf $release_dir/$release_tar_file ./kis-cli-$REL_VERSION
bzip2 -c $release_dir/$release_tar_file > $release_dir/$release_tar_compressed


sed -i -e "s/xxRELEASE_NAMExx/kis-cli-$REL_VERSION/g" $tmp_release_dir/README.md
sed -i -e "s/xxRELEASE_FILExx/$release_tar_compressed/g" $tmp_release_dir/README.md
pandoc -f markdown  --template=$script_dir/resources/pandoc/index_template.html --metadata title="kis-cli-$REL_VERSION README" $tmp_release_dir/README.md > $release_dir/index.html

rm $tmp_release_dir -r
rm $release_dir/$release_tar_file