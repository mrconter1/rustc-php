// stdout: 7
fn main() {
    let mut i: i32 = 0;
    let mut sum: i32 = 0;
    loop {
        i = i + 1;
        if i > 4 {
            break;
        }
        if i == 3 {
            continue;
        }
        sum = sum + i;
    }
    // sum = 1 + 2 + 4 = 7... wait, let me recalculate
    // i=1: not >4, not ==3, sum=1
    // i=2: not >4, not ==3, sum=3
    // i=3: not >4, ==3 → continue
    // i=4: not >4, not ==3, sum=7
    // i=5: >4 → break
    println!("{}", sum);
}
