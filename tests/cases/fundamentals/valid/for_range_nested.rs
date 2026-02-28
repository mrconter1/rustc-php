// exit: 0
// stdout: 9

fn main() {
    let mut count: i32 = 0;
    for _i in 0..3 {
        for _j in 0..3 {
            count = count + 1;
        }
    }
    println!("{}", count);
}
