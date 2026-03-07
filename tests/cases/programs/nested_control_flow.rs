// stdout: 1
// stdout: 2
// stdout: 4
// stdout: 5
// stdout: 7
// stdout: 8
// exit: 27

fn main() {
    let mut total: i32 = 0;
    for i in 0..10 {
        if i % 3 == 0 {
            continue;
        }
        println!("{}", i);
        total = total + i;
    }
    exit(total);
}
